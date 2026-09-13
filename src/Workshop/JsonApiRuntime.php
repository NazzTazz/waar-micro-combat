<?php

namespace Waar\MicroCombat\Workshop;

/** HTTP-only runtime: long, bounded calculations and JSON even after fatal errors. */
final class JsonApiRuntime
{
    public static function begin(string $path): void
    {
        // Keep finite timeouts; do not change php.ini or the budgets/seeds of the calculation.
        if($path==='/api/measure')set_time_limit(600);
        if(in_array($path,['/api/search','/api/optimize'],true))set_time_limit(4800);
        ini_set('display_errors','0');
        ini_set('log_errors','1');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $bufferLevel=ob_get_level();ob_start();
        $reserve=str_repeat(' ',65536);
        register_shutdown_function(static function() use ($bufferLevel,&$reserve): void {
            $error=error_get_last();
            if(null===$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true))return;
            $reserve=null;
            while(ob_get_level()>$bufferLevel)ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $message=str_contains($error['message'],'Maximum execution time')
                ?'Le calcul a dépassé la durée maximale autorisée. Aucun résultat partiel n’a été retenu.'
                :'Le serveur a interrompu le calcul. Consultez le journal PHP pour le détail. Aucun résultat partiel n’a été retenu.';
            echo json_encode(['ok'=>false,'errors'=>[['code'=>'calculation_interrupted','path'=>'','message'=>$message]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        });
    }
}
