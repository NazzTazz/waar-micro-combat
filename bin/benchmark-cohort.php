<?php
/** Paired PHP/Rust process benchmark; never invoked by the web application. */
require dirname(__DIR__).'/autoload.php';

use Waar\MicroCombat\Workshop\{EngineProfile,CohortRequestFactory,ProcessCohortRuntime};

$options=getopt('', ['combats:','seconds:','duration:','batch:','output:','profile:']);
$timed=isset($options['duration']);
$count=filter_var($options['combats']??100000,FILTER_VALIDATE_INT);
$seconds=filter_var($options['duration']??$options['seconds']??30,FILTER_VALIDATE_FLOAT);
$batch=filter_var($options['batch']??($timed?1:5),FILTER_VALIDATE_INT);
if($timed&&(isset($options['combats'])||isset($options['seconds']))){fwrite(STDERR,"Use --duration on its own, without --combats or --seconds.\n");exit(2);}
if(!$count||$count<32||$count>10000000||$count%32||!$seconds||$seconds<1||$seconds>3600||!$batch||$batch<1||$batch>100){
    fwrite(STDERR,"--combats: multiple of 32 (32..10000000); --seconds: 1..3600 per engine/workload; --batch: 1..100 repetitions/scenario.\n");exit(2);
}
if(isset($options['output'])&&file_exists($options['output'])){fwrite(STDERR,"Output already exists; choose another path.\n");exit(2);}
$root=dirname(__DIR__);
$profile=EngineProfile::fromArray(json_decode(file_get_contents($options['profile']??$root.'/resources/workshop-default-profile.json'),true,512,JSON_THROW_ON_ERROR));
$base=(new CohortRequestFactory())->monotypes($profile,'neutral',42,1);
$mixed=$base;$mixed['scenarios']=[];
$shares=['balanced'=>[25,25,25,25],'screen-knights'=>[30,0,0,70],'screen-archers'=>[30,0,70,0],'spear-assault'=>[20,40,0,40]];
$army=static function(array $shares)use($profile):array{
    $army=[];foreach(array_keys($profile->units)as$i=>$type)$army[$type]=intdiv(400400*$shares[$i],100*$profile->costs()[$type]);return $army;
};
foreach($shares as$a=>$left)foreach($shares as$d=>$right)$mixed['scenarios'][]=['id'=>"$a-vs-$d",'attacker'=>['units'=>$army($left),'modifiers'=>$profile->weatherModifiers('neutral','attacker')],'defender'=>['units'=>$army($right),'modifiers'=>$profile->weatherModifiers('neutral','defender')]];
$combined=$base;$combined['scenarios']=[...$base['scenarios'],...$mixed['scenarios']];
$workloads=$timed?['balanced'=>$combined]:['monotypes'=>$base,'mixed'=>$mixed];
// Sort object keys only: preserve ordered scenario lists and integer result types.
$canonical=function(mixed $value)use(&$canonical):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);return array_map($canonical,$value);};
$fingerprint=static fn(array $value):string=>hash('sha256',json_encode($canonical($value),JSON_THROW_ON_ERROR));
$report=['schemaVersion'=>'waar-cohort-paired-benchmark/1','timestamp'=>gmdate(DATE_ATOM),'machine'=>gethostname(),'os'=>php_uname(),'php'=>PHP_VERSION,
    'profileId'=>$profile->id,'profileFingerprint'=>$profile->semanticFingerprint(),'mode'=>$timed?'duration':'count','requestedCombatsPerEngine'=>$timed?null:$count,'secondsPerEngineWorkload'=>$seconds,'batchIterations'=>$batch,
    'method'=>'Sequential single-worker process-jsonl batches, including process startup, JSON, combat and consequence projection; no HTTP. One warm-up batch per engine/workload excluded. Time limit checked between batches.',
    'workloads'=>[]];
if($timed)$report['method']='Fixed-duration wall-clock throughput window per engine, including process startup, JSON, combat, consequences and driver bookkeeping; no HTTP. Each batch contains all 16 monotypes and 16 mixed scenarios equally. One warm-up batch excluded. Complete final batch before stopping; actual elapsed time reported.';
foreach($workloads as$name=>$template){
    $scenarioCount=count($template['scenarios']);
    $targetIterations=$timed?PHP_INT_MAX:intdiv($count,32);$phpHashes=[];$phpTimes=[];$measurements=[];
    foreach(['php','rust']as$kind){
        $runtime=new ProcessCohortRuntime(null,$kind);
        $warm=$runtime->batch($template);$warmHash=$fingerprint($warm);
        if($kind==='php')$referenceWarm=$warmHash;elseif($warmHash!==$referenceWarm)throw new RuntimeException("Warm-up parity failed: $name");
        $done=0;$elapsed=0.0;$matched=0;$matchedSeconds=0.0;$lot=0;$timings=[];
        $windowStarted=hrtime(true);
        while($done<$targetIterations&&$elapsed<$seconds){
            $request=$template;$request['iterations']=$request['totalIterations']=min($batch,$targetIterations-$done);
            // Distinct seeds across batches, identical inputs across languages/machines.
            $request['baseSeed']=42+$done;
            $started=hrtime(true);$result=$runtime->batch($request);$duration=(hrtime(true)-$started)/1e9;
            $hash=$fingerprint($result);
            if($kind==='php'){$phpHashes[$lot]=$hash;$phpTimes[$lot]=$duration;}
            elseif(isset($phpHashes[$lot])){if($hash!==$phpHashes[$lot])throw new RuntimeException("Result parity failed: $name batch $lot");$matched+=$result['totalCombats'];$matchedSeconds+=$duration;}
            $elapsed+=$duration;$done+=$request['iterations'];$timings[]=$duration;$lot++;
            if($timed)$elapsed=(hrtime(true)-$windowStarted)/1e9;
        }
        $combats=$done*$scenarioCount;
        $measurements[$kind]=['combats'=>$combats,'seconds'=>$elapsed,'combatsPerSecond'=>$combats/$elapsed,'completed'=>$timed||$done===$targetIterations,'batches'=>$lot,'batchSecondsMin'=>min($timings),'batchSecondsMax'=>max($timings),'estimatedSecondsForWorkloadTarget'=>$timed?null:($count/2)*$elapsed/$combats];
        if($kind==='rust'){
            $measurements['parity']=['matchedCombats'=>$matched,'warmupMatched'=>true,'allMeasuredCombatsMatched'=>$matched===$combats&&$matched===$measurements['php']['combats']];
            $measurements['matchedPrefixSpeedup']=array_sum(array_slice($phpTimes,0,$lot))/$matchedSeconds;
        }
        fwrite(STDERR,sprintf("%s %s: %d combats, %.2f s, %.1f combats/s%s\n",$name,$kind,$combats,$elapsed,$combats/$elapsed,$timed?' (timed window)':($done===$targetIterations?'':' (time budget reached)')));
    }
    $report['workloads'][$name]=['inputFingerprint'=>$fingerprint($template),'scenarios'=>$template['scenarios'],'measurements'=>$measurements];
}
$report['driverPeakMemoryBytes']=memory_get_peak_usage(true); // Not child-process RSS.
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
if(isset($options['output'])){if(file_exists($options['output']))throw new RuntimeException('Output already exists; choose another path.');if(file_put_contents($options['output'],$json,LOCK_EX)===false)throw new RuntimeException('Cannot write report.');}
echo $json;
