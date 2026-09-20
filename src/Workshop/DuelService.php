<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\FixedPoint;

final class DuelService
{
    private CohortRuntime $runtime;
    public function __construct(?CohortRuntime $runtime=null,private readonly CohortRequestFactory $requests=new CohortRequestFactory()){$this->runtime=$runtime??new ProcessCohortRuntime();}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function simulate(array $request):array
    {
        $errors=[];$armies=[];
        foreach(['A','B']as$name){$army=$request['armies'][$name]??null;if(!is_array($army)||([]!==$army&&array_is_list($army))){$errors[]=['code'=>'invalid_army','path'=>'armies.'.$name,'message'=>'Armée invalide.'];continue;}foreach(array_diff(array_keys($army),array_keys(EngineProfile::UNIT_COSTS))as$type)$errors[]=['code'=>'unknown_unit','path'=>'armies.'.$name.'.'.$type,'message'=>'Type d’unité inconnu.'];$total=0;$normalized=[];foreach(EngineProfile::UNIT_COSTS as$type=>$cost){$value=$army[$type]??0;if(!is_int($value)||$value<0||$value>100000)$errors[]=['code'=>'out_of_range','path'=>'armies.'.$name.'.'.$type,'message'=>'Effectif entier attendu entre 0 et 100 000.'];else{$normalized[$type]=$value;$total=FixedPoint::checkedAdd($total,$value);}}if($total>100000)$errors[]=['code'=>'out_of_range','path'=>'armies.'.$name,'message'=>'Chaque camp est limité à 100 000 unités au total.'];if($total===0)$errors[]=['code'=>'empty_army','path'=>'armies.'.$name,'message'=>'Chaque camp doit contenir au moins une unité.'];$armies[$name]=$normalized;}
        $seed=$request['seed']??null;if(!is_int($seed)||$seed<0||$seed>2147483647)$errors[]=['code'=>'invalid_seed','path'=>'seed','message'=>'Seed entière attendue entre 0 et 2³¹−1.'];
        $weather=is_string($request['weather']??null)?['A'=>$request['weather'],'B'=>$request['weather']]:($request['weather']??['A'=>'neutral','B'=>'neutral']);
        if(!is_array($weather)||array_is_list($weather)){$errors[]=['code'=>'invalid_weather','path'=>'weather','message'=>'Une météo est requise pour chaque camp.'];$weather=['A'=>'neutral','B'=>'neutral'];}
        foreach(['A','B']as$camp)if(!is_string($weather[$camp]??null)||!in_array($weather[$camp],EngineProfile::WEATHER,true))$errors[]=['code'=>'unknown_weather','path'=>'weather.'.$camp,'message'=>'Condition météo inconnue.'];
        $modifiers=$request['modifiers']??['A'=>[],'B'=>[]];if(!is_array($modifiers)||array_is_list($modifiers)){$errors[]=['code'=>'invalid_modifiers','path'=>'modifiers','message'=>'Les effets doivent être séparés par camp.'];$modifiers=['A'=>[],'B'=>[]];}
        foreach(['A','B']as$camp)if(!is_array($modifiers[$camp]??[])||!array_is_list($modifiers[$camp]??[]))$errors[]=['code'=>'invalid_modifiers','path'=>'modifiers.'.$camp,'message'=>'Liste d’effets invalide.'];
        $active=[];foreach($armies as$army)foreach($army as$type=>$count)if($count>0)$active[$type]=true;
        try{$profile=EngineProfile::fromArray(is_array($request['profile']??null)?$request['profile']:[],array_keys($active));}catch(ProfileValidationException$e){$errors=[...$errors,...$e->errors];$profile=null;}
        if($errors)throw new ProfileValidationException($errors);
        $directions=[];
        foreach([['id'=>'a-attacks-b','attacker'=>'A','defender'=>'B'],['id'=>'b-attacks-a','attacker'=>'B','defender'=>'A']]as$direction){
            $engineRequest=$this->requests->combat($profile,$armies[$direction['attacker']],$armies[$direction['defender']],$seed,$weather[$direction['attacker']],$weather[$direction['defender']],$modifiers[$direction['attacker']]??[],$modifiers[$direction['defender']]??[],'full');
            $report=$this->runtime->resolve($engineRequest);$winner=$report['result']['winner'];
            $directions[]=['id'=>$direction['id'],'labels'=>['attacker'=>$direction['attacker'],'defender'=>$direction['defender'],'winner'=>$winner===null?null:($winner==='attacker'?$direction['attacker']:$direction['defender'])],
                'weather'=>['attacker'=>$weather[$direction['attacker']],'defender'=>$weather[$direction['defender']]],'report'=>$report,'result'=>$report['result'],'consequences'=>$report['consequences']??null];
        }
        $cost=static fn(array$army):int=>array_sum(array_map(static fn(string$type,int$count):int=>$count*$profile->costs()[$type],array_keys($army),$army));
        return ['schemaVersion'=>'waar-instant-duel-result/0.2','modelVersion'=>EngineProfile::MODEL_VERSION,'runtime'=>$this->runtime->provenance(),'requestId'=>(string)($request['requestId']??''),'profileFingerprint'=>$profile->semanticFingerprint(),'weather'=>$weather,'seed'=>$seed,'costs'=>['A'=>$cost($armies['A']),'B'=>$cost($armies['B'])],'directions'=>$directions,'notice'=>'Essai ponctuel : deux simulations ne constituent pas une estimation de taux de victoire.'];
    }
}
