<?php

namespace Waar\MicroCombat;

/** Individual targets and wounds; the historical pooled resolver remains reproducible. */
final class SingleTargetCombatResolver
{
    public const MODEL = 'single-target-simultaneous-v1';
    public const MAX_COMBATANTS_PER_SIDE = 400400;

    public function resolve(PreparedBattle $battle, ?callable $onRound = null): BattleResult
    {
        $states=['attacker'=>$this->initialState($battle->attacker), 'defender'=>$this->initialState($battle->defender)];
        $rounds=[];
        $outcome=$this->extinction($states,$battle->ruleset->tieBreakPolicy);
        $damageRandom=new Lcg31($battle->seed);
        // Separate stream: targeting never consumes the historical round-factor draws.
        $targetSeed=(int)hexdec(substr(hash('sha256',self::MODEL.':targets:'.$battle->seed),0,8)) & 0x7fffffff;
        $targetRandom=new Lcg31($targetSeed);
        for($round=1;null===$outcome && $round<=$battle->ruleset->maxRounds;++$round){
            $before=null===$onRound?null:$this->snapshot($states);
            $aFactor=$damageRandom->nextFactor($battle->ruleset->randomSpreadMicro);
            $bFactor=$damageRandom->nextFactor($battle->ruleset->randomSpreadMicro);
            // Both lists of eligible sources/targets are frozen at round start.
            $aHealth=$states['attacker']['health'];$bHealth=$states['defender']['health'];
            [$aDamage,$aTrace]=$this->strike($states['attacker'],$states['defender'],$bHealth,false,$aFactor,$battle->ruleset,$targetRandom,null!==$onRound);
            [$bDamage,$bTrace]=$this->strike($states['defender'],$states['attacker'],$aHealth,true,$bFactor,$battle->ruleset,$targetRandom,null!==$onRound);
            $states['attacker']=$this->finishRound($states['attacker'],$aHealth);
            $states['defender']=$this->finishRound($states['defender'],$bHealth);
            $rounds[]=['number'=>$round,'randomFactorMicro'=>['attacker'=>$aFactor,'defender'=>$bFactor],
                'damageByTargetMicro'=>['attacker'=>$bDamage,'defender'=>$aDamage]];
            if(null!==$onRound)$onRound(['number'=>$round,'targetingModel'=>self::MODEL,'before'=>$before,
                'after'=>$this->snapshot($states),'attacks'=>['attacker'=>$aTrace,'defender'=>$bTrace]]);
            $outcome=$this->extinction($states,$battle->ruleset->tieBreakPolicy);
        }
        if(null===$outcome){
            $comparison=CombatResolver::compareFractions(array_sum($states['attacker']['structure']),array_sum($states['attacker']['initialStructure']),array_sum($states['defender']['structure']),array_sum($states['defender']['initialStructure']));
            $outcome=match($comparison){
                1=>[CombatSide::Attacker,'round-limit-preservation'],
                -1=>[CombatSide::Defender,'round-limit-preservation'],
                default=>$battle->ruleset->tieBreakPolicy===CombatTieBreakPolicy::Defender
                    ?[CombatSide::Defender,'defender-tie-break-round-limit-equality']:[null,'round-limit-equality'],
            };
        }
        return new BattleResult($outcome[0],$outcome[1],count($rounds),$this->sideOutcome($states['attacker']),$this->sideOutcome($states['defender']),$rounds,$battle->ruleset->id,$battle->ruleset->version,$battle->seed,self::MODEL);
    }

    private function initialState(PreparedArmy $army): array
    {
        if($army->totalCount()>self::MAX_COMBATANTS_PER_SIDE)throw new \InvalidArgumentException('Le modèle individuel accepte au plus 400 400 combattants par camp.');
        $units=$counts=$structure=$health=$types=[];
        foreach($army->units() as $unit){
            $type=$unit->type->value;$units[$type]=$unit;$counts[$type]=$unit->count;
            $structure[$type]=FixedPoint::checkedMultiply($unit->count,$unit->structureMicro);
            for($i=0;$i<$unit->count;++$i){$health[]=$unit->structureMicro;$types[]=$type;}
        }
        return ['units'=>$units,'initial'=>$counts,'initialStructure'=>$structure,'counts'=>$counts,'structure'=>$structure,'health'=>$health,'types'=>$types];
    }

    /** Each source gets one draw, even if its strike does zero damage. */
    private function strike(array $acting,array $target,array &$remainingHealth,bool $defending,int $randomFactor,CombatRuleset $ruleset,Lcg31 $random,bool $tracing): array
    {
        $damage=array_fill_keys(array_keys($target['units']),0);$trace=[];
        $targetCount=count($target['health']);
        foreach($acting['units'] as $sourceType=>$unit){
            $sourceCount=$acting['counts'][$sourceType];if(0===$sourceCount)continue;
            $roleFactor=$defending?$unit->defendingEfficiencyMicro:FixedPoint::SCALE;
            $afterDefense=FixedPoint::mulDivNearest($unit->attackMicro,$roleFactor,FixedPoint::SCALE);
            $afterRandom=FixedPoint::mulDivNearest($afterDefense,$randomFactor,FixedPoint::SCALE);
            $perHit=$impacts=[];
            foreach($target['units'] as $targetType=>$targetUnit){
                $perHit[$targetType]=FixedPoint::mulDivNearest($afterRandom,$ruleset->damageFactor($unit->type,$targetUnit->type),FixedPoint::SCALE);
                $impacts[$targetType]=0;
            }
            for($i=0;$i<$sourceCount;++$i){
                $index=$random->nextIndex($targetCount);
                $type=$target['types'][$index];++$impacts[$type];
                // No retargeting and no spillover, even if another simultaneous hit is lethal.
                $remainingHealth[$index]=max(0,$remainingHealth[$index]-$perHit[$type]);
            }
            foreach($impacts as $type=>$count){
                $contribution=FixedPoint::checkedMultiply($count,$perHit[$type]);
                $damage[$type]=FixedPoint::checkedAdd($damage[$type],$contribution);
                if(!$tracing || 0===$target['counts'][$type])continue;
                $trace[]=['source'=>$sourceType,'target'=>$type,'sourceCount'=>$sourceCount,
                    'targetCount'=>$target['counts'][$type],'totalTargetCount'=>$targetCount,'impactCount'=>$count,
                    'unitAttackMicro'=>$unit->attackMicro,'damagePerHitMicro'=>$perHit[$type],
                    'baseAttackMicro'=>FixedPoint::checkedMultiply($count,$unit->attackMicro),
                    'defendingFactorMicro'=>$roleFactor,'unitAfterDefenseMicro'=>$afterDefense,
                    'afterDefenseMicro'=>FixedPoint::checkedMultiply($count,$afterDefense),
                    'randomFactorMicro'=>$randomFactor,'unitAfterRandomMicro'=>$afterRandom,
                    'afterRandomMicro'=>FixedPoint::checkedMultiply($count,$afterRandom),
                    'counterFactorMicro'=>$ruleset->damageFactor($unit->type,$target['units'][$type]->type),
                    'damageMicro'=>$contribution];
            }
        }
        return [$damage,$trace];
    }

    /** Keep individual wounds; compacting only removes the dead after simultaneous strikes. */
    private function finishRound(array $state,array $health): array
    {
        $counts=$structure=array_fill_keys(array_keys($state['units']),0);$livingHealth=$livingTypes=[];
        foreach($health as $i=>$remaining){
            if(0===$remaining)continue;
            $type=$state['types'][$i];$livingHealth[]=$remaining;$livingTypes[]=$type;
            ++$counts[$type];$structure[$type]=FixedPoint::checkedAdd($structure[$type],$remaining);
        }
        $state['health']=$livingHealth;$state['types']=$livingTypes;$state['counts']=$counts;$state['structure']=$structure;
        return $state;
    }

    private function snapshot(array $states): array
    {
        $snapshot=[];
        foreach($states as $side=>$state)foreach($state['units'] as $type=>$unit){
            $count=$state['counts'][$type];$factor=$side==='defender'?$unit->defendingEfficiencyMicro:FixedPoint::SCALE;
            $snapshot[$side][$type]=['count'=>$count,'attackMicro'=>FixedPoint::checkedMultiply($count,$unit->attackMicro),
                'defendingFactorMicro'=>$factor,'effectiveAttackMicro'=>FixedPoint::checkedMultiply($count,FixedPoint::mulDivNearest($unit->attackMicro,$factor,FixedPoint::SCALE)),
                'structureMicro'=>$state['structure'][$type]];
        }
        return $snapshot;
    }

    private function extinction(array $states,CombatTieBreakPolicy $policy): ?array
    {
        $a=[]===$states['attacker']['health'];$b=[]===$states['defender']['health'];
        return match(true){
            $a&&$b=>$policy===CombatTieBreakPolicy::Defender?[CombatSide::Defender,'defender-tie-break-mutual-extinction']:[null,'mutual-extinction'],
            $a=>[CombatSide::Defender,'attacker-extinction'],$b=>[CombatSide::Attacker,'defender-extinction'],default=>null,
        };
    }

    private function sideOutcome(array $state): SideOutcome
    {
        $outcomes=[];
        foreach($state['units'] as $type=>$unit)$outcomes[]=new UnitOutcome($unit->type,$state['initial'][$type],$state['counts'][$type],$state['initial'][$type]-$state['counts'][$type],$state['initialStructure'][$type],$state['structure'][$type],$unit->cost);
        return new SideOutcome($outcomes);
    }
}
