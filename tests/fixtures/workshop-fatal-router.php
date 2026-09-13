<?php

require dirname(__DIR__,2).'/autoload.php';

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
\Waar\MicroCombat\Workshop\JsonApiRuntime::begin($path);
if($path==='/api/timeout'){
    set_time_limit(1);
    $started=microtime(true);
    while(microtime(true)-$started<3){}
}
if($path==='/api/fatal'){
    echo '{"partial":true}';
    trigger_error('Intentional fatal error for the JSON runtime regression.',E_USER_ERROR);
}
echo json_encode(['ok'=>true,'limit'=>(int)ini_get('max_execution_time')]);
