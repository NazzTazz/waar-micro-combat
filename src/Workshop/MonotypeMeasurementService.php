<?php

namespace Waar\MicroCombat\Workshop;

final class MonotypeMeasurementService
{
    public const BUDGET=400400;
    private CohortRuntime $runtime;
    public function __construct(?CohortRuntime $runtime=null,private readonly CohortRequestFactory $requests=new CohortRequestFactory()){$this->runtime=$runtime??new ProcessCohortRuntime();}

    /** @param array<string,mixed> $profileValues @return array<string,mixed> */
    public function measure(array $profileValues,string $weather='neutral',int $baseSeed=42,int $iterations=100):array
    {
        if($iterations<1||$iterations>100)throw new \InvalidArgumentException('La mesure accepte de 1 à 100 répétitions.');
        if($baseSeed<0||$baseSeed>2147483647)throw new \InvalidArgumentException('Seed invalide.');
        if(!in_array($weather,EngineProfile::WEATHER,true))throw new \InvalidArgumentException('Condition météo inconnue.');
        $profile=EngineProfile::fromArray($profileValues);$request=$this->requests->monotypes($profile,$weather,$baseSeed,$iterations);$batch=$this->runtime->batch($request);
        CohortRequestFactory::assertProvenance($batch['consequenceProvenance']??[], $request['consequences']);
        CohortRequestFactory::assertBatchRandomProvenance($batch,$request['scenarios']);
        if(($batch['unitOrder']??null)!==array_keys(EngineProfile::UNIT_COSTS)||($batch['projectedCategoryOrder']??null)!==['healthy','wounded','dead','prisoners'])throw new \RuntimeException('Ordre d’agrégation du batch cohortes incompatible.');
        $rows=[];
        foreach($batch['scenarios'] as $scenario)foreach(['attacker','defender'] as $side)if(!isset($scenario['result'][$side.'RawWoundedByType']))throw new \RuntimeException('Runtime obsolète : reconstruisez Rust pour mesurer les blessés bruts.');
        foreach($batch['scenarios']as$scenario){$id=$scenario['id'];$result=$scenario['result'];[$attacking,$defending]=explode('-vs-',$id,2);
            foreach(['attacker','defender']as$side){$prefix=$side==='attacker'?'attacker':'defender';$initialByType=$result[$prefix.'InitialByType'];$rawByType=$result[$prefix.'RawDeathsByType'];$projected=$result[$prefix.'ProjectedByType'];$type=$side==='attacker'?$attacking:$defending;$index=array_search($type,$batch['unitOrder'],true);if($index===false)throw new \RuntimeException('Type monotype absent du batch.');$initial=$initialByType[$index]*$iterations;$raw=$rawByType[$index];$p=$projected[$index];$wins=$result[$prefix.'Wins'];
                $rows[]=['id'=>$id.'/'.$side,'scenarioId'=>$id,'attackerType'=>$attacking,'defenderType'=>$defending,'side'=>$side,'initialCount'=>$initialByType[$index],'winRate'=>$wins/$iterations,'drawRate'=>$result['draws']/$iterations,'rawLossRatio'=>$initial?$raw/$initial:0.0,'rawWoundedRatio'=>$initial?$result[$prefix.'RawWoundedByType'][$index]/$initial:0.0,'rawCasualtyRatio'=>$initial?($raw+$result[$prefix.'RawWoundedByType'][$index])/$initial:0.0,'appliedLossRatio'=>$initial?$p[2]/$initial:0.0,'woundedRatio'=>$initial?$p[1]/$initial:0.0,'captureRatio'=>$initial?$p[3]/$initial:0.0,'freeRatio'=>$initial?($p[0]+$p[1])/$initial:0.0,'iterations'=>$iterations];
            }
        }
        $context=['weather'=>$weather,'baseSeed'=>$baseSeed,'iterations'=>$iterations,'budget'=>self::BUDGET,'objectiveMetric'=>'rawCasualtyRatio','modelVersion'=>EngineProfile::MODEL_VERSION,'rulesetVersion'=>$request['ruleset']['version'],'runtime'=>$this->runtime->provenance(),'consequences'=>CohortRequestFactory::consequenceContext($profile)];
        $mechanisms=[];$description=new MonotypeMechanics();
        foreach($request['scenarios'] as $scenario)$mechanisms[$scenario['id']]=$description->describe($profile,$weather,$scenario['id']);
        return ['schemaVersion'=>'waar-monotype-consequence-observations/0.2','profileFingerprint'=>$profile->semanticFingerprint(),'modelVersion'=>EngineProfile::MODEL_VERSION,'context'=>$context,'batch'=>['schemaVersion'=>$batch['schemaVersion'],'iterationRange'=>$batch['iterationRange'],'totalCombats'=>$batch['totalCombats']],'rows'=>$rows,'mechanisms'=>$mechanisms];
    }
}
