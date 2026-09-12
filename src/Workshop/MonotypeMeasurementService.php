<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\CombatResolver;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\PreparedBattle;
use Waar\MicroCombat\UnitType;

final readonly class MonotypeMeasurementService
{
    public const BUDGET=400400;
    public function __construct(private CombatResolver $resolver=new CombatResolver(),private BattleConsequences $consequences=new BattleConsequences()){}

    /** @param array<string,mixed> $profileValues @return array<string,mixed> */
    public function measure(array $profileValues,string $weather='neutral',int $baseSeed=42,int $iterations=100):array
    {
        if($iterations<1||$iterations>100)throw new \InvalidArgumentException('La mesure V1 accepte de 1 à 100 répétitions.');
        if($baseSeed<0||$baseSeed>2147483647)throw new \InvalidArgumentException('Seed invalide.');
        $profile=EngineProfile::fromArray($profileValues);$rows=[];
        foreach(UnitType::cases() as $attacking)foreach(UnitType::cases() as $defending){
            $scenario=$attacking->value.'-vs-'.$defending->value;$countsA=[$attacking->value=>intdiv(self::BUDGET,EngineProfile::UNIT_COSTS[$attacking->value])];$countsB=[$defending->value=>intdiv(self::BUDGET,EngineProfile::UNIT_COSTS[$defending->value])];
            $sums=['attacker'=>['wins'=>0,'losses'=>0,'initial'=>0,'captured'=>0,'free'=>0],'defender'=>['wins'=>0,'losses'=>0,'initial'=>0,'captured'=>0,'free'=>0]];
            for($iteration=0;$iteration<$iterations;++$iteration){
                $seed=ExperimentRunner::deriveSeed($baseSeed,$scenario,$iteration);$raw=$this->resolver->resolve(new PreparedBattle($profile->ruleset(),$profile->prepareArmy($countsA,$weather),$profile->prepareArmy($countsB,$weather),$seed));$result=$this->consequences->apply($raw,$profile);
                foreach([CombatSide::Attacker,CombatSide::Defender] as $side){$key=$side->value;$sums[$key]['wins']+=(int)($raw->winner===$side);foreach($result['consequences'][$key]['units'] as $unit){$sums[$key]['losses']+=$unit['appliedLosses'];$sums[$key]['initial']+=$unit['initial'];$sums[$key]['captured']+=$unit['prisoners'];$sums[$key]['free']+=$unit['free'];}}
            }
            foreach($sums as $side=>$sum)$rows[]=['id'=>$scenario.'/'.$side,'scenarioId'=>$scenario,'attackerType'=>$attacking->value,'defenderType'=>$defending->value,'side'=>$side,'winRate'=>$sum['wins']/$iterations,'appliedLossRatio'=>$sum['initial']?($sum['losses']/$sum['initial']):0.0,'captureRatio'=>$sum['initial']?($sum['captured']/$sum['initial']):0.0,'freeRatio'=>$sum['initial']?($sum['free']/$sum['initial']):0.0,'iterations'=>$iterations];
        }
        return ['schemaVersion'=>'waar-monotype-consequence-observations/0.1','profileFingerprint'=>$profile->semanticFingerprint(),'modelVersion'=>EngineProfile::MODEL_VERSION,'context'=>['weather'=>$weather,'baseSeed'=>$baseSeed,'iterations'=>$iterations,'budget'=>self::BUDGET,'consequences'=>['lossCompressionPercent'=>$profile->lossCompressionPercent,'capturePercent'=>$profile->capturePercent]],'rows'=>$rows];
    }
}
