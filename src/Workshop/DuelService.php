<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\CombatResolver;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\PreparedBattle;

final readonly class DuelService
{
    public function __construct(private CombatResolver $resolver=new CombatResolver(),private BattleConsequences $consequences=new BattleConsequences()){}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function simulate(array $request):array
    {
        $errors=[];$armies=[];
        foreach(['A','B'] as $name){$army=$request['armies'][$name]??null;if(!is_array($army)||([]!==$army&&array_is_list($army))){$errors[]=['code'=>'invalid_army','path'=>'armies.'.$name,'message'=>'Armée invalide.'];continue;}foreach(array_diff(array_keys($army),array_keys(EngineProfile::UNIT_COSTS)) as $type)$errors[]=['code'=>'unknown_unit','path'=>'armies.'.$name.'.'.$type,'message'=>'Type d’unité inconnu.'];$total=0;$normalized=[];foreach(EngineProfile::UNIT_COSTS as $type=>$cost){$value=$army[$type]??0;if(!is_int($value)||$value<0||$value>10000)$errors[]=['code'=>'out_of_range','path'=>'armies.'.$name.'.'.$type,'message'=>'Effectif entier attendu entre 0 et 10 000.'];else{$normalized[$type]=$value;$total=FixedPoint::checkedAdd($total,$value);}}if($total===0)$errors[]=['code'=>'empty_army','path'=>'armies.'.$name,'message'=>'Chaque camp doit contenir au moins une unité.'];$armies[$name]=$normalized;}
        $seed=$request['seed']??null;if(!is_int($seed)||$seed<0||$seed>2147483647)$errors[]=['code'=>'invalid_seed','path'=>'seed','message'=>'Seed entière attendue entre 0 et 2³¹−1.'];
        $weather=$request['weather']??'neutral';if(!is_string($weather)||!in_array($weather,EngineProfile::WEATHER,true))$errors[]=['code'=>'unknown_weather','path'=>'weather','message'=>'Condition météo inconnue.'];
        $active=[];foreach($armies as $army)foreach($army as $type=>$count)if($count>0)$active[$type]=true;
        try{$profile=EngineProfile::fromArray(is_array($request['profile']??null)?$request['profile']:[],array_keys($active));}catch(ProfileValidationException $e){$errors=[...$errors,...$e->errors];$profile=null;}
        if($errors)throw new ProfileValidationException($errors);
        $result=[];
        foreach([['id'=>'a-attacks-b','attacker'=>'A','defender'=>'B'],['id'=>'b-attacks-a','attacker'=>'B','defender'=>'A']] as $direction){
            $raw=$this->resolver->resolve(new PreparedBattle($profile->ruleset(),$profile->prepareArmy($armies[$direction['attacker']],$weather),$profile->prepareArmy($armies[$direction['defender']],$weather),$seed));
            $payload=$this->consequences->apply($raw,$profile);$payload['id']=$direction['id'];$payload['labels']=['attacker'=>$direction['attacker'],'defender'=>$direction['defender'],'winner'=>$raw->winner===null?null:($raw->winner===CombatSide::Attacker?$direction['attacker']:$direction['defender'])];$result[]=$payload;
        }
        $cost=static fn(array $army):int=>array_sum(array_map(static fn(string $type,int $count):int=>$count*EngineProfile::UNIT_COSTS[$type],array_keys($army),$army));
        return ['schemaVersion'=>'waar-instant-duel-result/0.1','requestId'=>(string)($request['requestId']??''),'profileFingerprint'=>$profile->semanticFingerprint(),'weather'=>$weather,'seed'=>$seed,'costs'=>['A'=>$cost($armies['A']),'B'=>$cost($armies['B'])],'directions'=>$result,'notice'=>'Essai ponctuel : deux simulations ne constituent pas une estimation de taux de victoire.'];
    }
}
