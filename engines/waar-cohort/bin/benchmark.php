<?php

require dirname(__DIR__).'/autoload.php';

use App\Game\Combat\DemoRequestFactory;
use App\Infrastructure\Combat\RustCombatResolver;

$rust=new RustCombatResolver(dirname(__DIR__));$duel=DemoRequestFactory::combat('none');
$started=hrtime(true);for($i=0;$i<100;++$i){$duel['seed']=$i;$last=$rust->resolveRequest($duel);}$duelSeconds=(hrtime(true)-$started)/1e9;
$raw=DemoRequestFactory::batch();unset($raw['consequences']);$started=hrtime(true);$rawResult=$rust->resolveBatch($raw);$rawSeconds=(hrtime(true)-$started)/1e9;
$projected=DemoRequestFactory::batch();$started=hrtime(true);$projectedResult=$rust->resolveBatch($projected);$projectedSeconds=(hrtime(true)-$started)/1e9;
echo json_encode(['schemaVersion'=>'waar-cohort-benchmark/1','runtime'=>['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'ffi'=>extension_loaded('ffi')],
    'duel'=>['samples'=>100,'seconds'=>$duelSeconds,'combatsPerSecond'=>100/$duelSeconds],
    'monotypesRaw'=>['combats'=>$rawResult['totalCombats'],'seconds'=>$rawSeconds,'combatsPerSecond'=>$rawResult['totalCombats']/$rawSeconds],
    'monotypesProjected'=>['combats'=>$projectedResult['totalCombats'],'seconds'=>$projectedSeconds,'combatsPerSecond'=>$projectedResult['totalCombats']/$projectedSeconds],
    'mixedFinalCohorts'=>['attacker'=>count($last['result']['attacker']['cohorts']),'defender'=>count($last['result']['defender']['cohorts'])],
    'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
