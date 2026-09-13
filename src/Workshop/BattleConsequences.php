<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\BattleResult;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\UnitType;

final readonly class BattleConsequences
{
    /** @return array<string,mixed> */
    public function apply(BattleResult $result, EngineProfile $profile): array
    {
        $sides=[];
        foreach ([CombatSide::Attacker,CombatSide::Defender] as $side) {
            $outcome=$side===CombatSide::Attacker?$result->attacker:$result->defender;
            $lost=$captured=$free=0;$units=[];
            foreach(UnitType::cases() as $type){
                $raw=$outcome->unit($type);$applied=self::floorPercent($raw->dead,$profile->lossCompressionPercent);$before=$raw->initial-$applied;
                $isDefeated=null!==$result->winner&&$result->winner!==$side;
                $prisoners=$isDefeated&&true===($profile->units[$type->value]['capturable']??false)?self::floorPercent($before,$profile->capturePercent):0;
                $remaining=$before-$prisoners;
                $lost=FixedPoint::checkedAdd($lost,$applied);$captured=FixedPoint::checkedAdd($captured,$prisoners);$free=FixedPoint::checkedAdd($free,$remaining);
                if($raw->initial!==$applied+$prisoners+$remaining)throw new \LogicException('Conservation des effectifs rompue.');
                $units[$type->value]=['initial'=>$raw->initial,'rawLosses'=>$raw->dead,'appliedLosses'=>$applied,'survivorsBeforeCapture'=>$before,'prisoners'=>$prisoners,'free'=>$remaining,'capturable'=>(bool)($profile->units[$type->value]['capturable']??false)];
            }
            $sides[$side->value]=['units'=>$units,'totals'=>['appliedLosses'=>$lost,'prisoners'=>$captured,'free'=>$free]];
        }
        return ['schemaVersion'=>'waar-battle-consequences/0.1','modelVersion'=>EngineProfile::MODEL_VERSION,'raw'=>$result->toArray(),'consequences'=>$sides,'parameters'=>['lossCompressionPercent'=>$profile->lossCompressionPercent,'capturePercent'=>$profile->capturePercent]];
    }

    private static function floorPercent(int $value,int $percent): int
    {
        if($value<0||$percent<0||$percent>100)throw new \InvalidArgumentException('Pourcentage ou effectif invalide.');
        return intdiv($value,100)*$percent+intdiv(($value%100)*$percent,100);
    }
}
