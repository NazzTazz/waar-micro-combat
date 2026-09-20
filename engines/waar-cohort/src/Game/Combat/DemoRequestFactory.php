<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Rules\CombatRuleset;
use App\Game\Combat\Rules\WaarRuleset;

final class DemoRequestFactory
{
    /** @return array<string,mixed> */
    public static function combat(string $traceLevel='full'):array
    {
        $rules=WaarRuleset::create('slice-a-demo')->toArray();$rules['maxRounds']=4;
        foreach($rules['units'] as &$unit)if($unit['type']==='knight')$unit['strikesPerAttack']=5;unset($unit);
        $rules['engagements']['spearman']['knight']['attackFactor']='5';
        return ['schemaVersion'=>CombatEngine::REQUEST_SCHEMA,'ruleset'=>$rules,'seed'=>9381,'traceLevel'=>$traceLevel,
            'attacker'=>['units'=>['soldier'=>350,'spearman'=>40,'archer'=>30,'knight'=>4],'modifiers'=>[
                self::modifier('training','archer-drill','Formation archers',UnitType::Archer,'baseAccuracy','1.2'),
                self::modifier('training','knight-discipline','Discipline chevaliers',UnitType::Knight,'accuracySpread','0.5'),
                self::modifier('weather','attacker-heat','Chaleur',UnitType::Soldier,'attack','0.9'),
                self::modifier('weather','attacker-wind','Vent fort',UnitType::Archer,'baseAccuracy','0.85'),
            ]],
            'defender'=>['units'=>['soldier'=>280,'spearman'=>55,'archer'=>22,'knight'=>5],'modifiers'=>[
                self::modifier('training','shield-wall','Mur de boucliers',UnitType::Spearman,'defendingEfficiency','1.1'),
                self::modifier('weather','defender-cold','Froid',UnitType::Spearman,'baseAccuracy','0.9'),
            ]],
            'consequences'=>['compressionPercent'=>8,'capturePercent'=>3]];
    }

    /** @return array<string,mixed> */
    public static function batch(int $iterations=100):array
    {
        $combat=self::combat('none');$scenarios=[];$costs=['soldier'=>10,'spearman'=>70,'archer'=>70,'knight'=>550];$types=array_keys($costs);
        foreach($types as $aIndex=>$a)foreach($types as $dIndex=>$d)$scenarios[]=['id'=>"{$a}-vs-{$d}",'seedKey'=>$aIndex*4+$dIndex,
            'attacker'=>['units'=>[$a=>intdiv(400400,$costs[$a])],'modifiers'=>$combat['attacker']['modifiers']],
            'defender'=>['units'=>[$d=>intdiv(400400,$costs[$d])],'modifiers'=>$combat['defender']['modifiers']]];
        return ['schemaVersion'=>'waar-combat-batch-request/2','ruleset'=>$combat['ruleset'],'baseSeed'=>9381,'iterations'=>$iterations,'startIteration'=>0,
            'consequences'=>$combat['consequences'],'scenarios'=>$scenarios];
    }

    /** @return array<string,mixed> */
    private static function modifier(string $source,string $id,string $label,UnitType $type,string $parameter,string $value):array
    {return ['source'=>$source,'id'=>$id,'label'=>$label,'unitType'=>$type->value,'parameter'=>$parameter,'operation'=>'multiply','value'=>$value];}
}
