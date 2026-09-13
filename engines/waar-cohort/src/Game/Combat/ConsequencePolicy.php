<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Rules\CombatRuleset;

final class ConsequencePolicy
{
    public const VERSION='wounded-capture-then-compress/1';

    /** @return array<string,mixed> */
    public function project(CombatResult $result,CombatRuleset $ruleset,int $compressionPercent,int $capturePercent):array
    {
        if($compressionPercent<0||$compressionPercent>100||$capturePercent<0||$capturePercent>10)throw new \InvalidArgumentException('Compression must be 0..100 and capture 0..10 percent.');
        return ['schemaVersion'=>'waar-combat-consequences/1','policyVersion'=>self::VERSION,'rawResult'=>$result->replayHash,
            'compressionPercent'=>$compressionPercent,'capturePercent'=>$capturePercent,
            'attacker'=>$this->side($result->attackerArmy,$result->attackerPrepared,$result->winner===CombatSide::Defender,$compressionPercent,$capturePercent),
            'defender'=>$this->side($result->defenderArmy,$result->defenderPrepared,$result->winner===CombatSide::Attacker,$compressionPercent,$capturePercent)];
    }

    /** @return array<string,mixed> */
    private function side(CombatArmy $army,\App\Game\Combat\Preparation\PreparedCombatSide $prepared,bool $defeated,int $compression,int $capture):array
    {
        $types=[];$initialCost=$lostCost=0;
        foreach(UnitType::cases() as $type){$initial=$army->initialCount($type);$dead=$army->deadCount($type);$wounded=$army->woundedCount($prepared,$type);$healthy=$initial-$dead-$wounded;
            $selected=$defeated&&$prepared->unit($type)->capturable?intdiv($wounded*$capture,100):0;$freeWounded=$wounded-$selected;
            $deadOut=intdiv($dead*$compression,100);$woundedOut=intdiv($freeWounded*$compression,100);$prisonersOut=intdiv($selected*$compression,100);$healthyOut=$initial-$deadOut-$woundedOut-$prisonersOut;
            $cost=$prepared->unit($type)->cost;$initialCost+=$initial*$cost;$lostCost+=($deadOut+$prisonersOut)*$cost;
            $types[$type->value]=['initial'=>$initial,'raw'=>['healthy'=>$healthy,'wounded'=>$wounded,'dead'=>$dead],
                'projected'=>['healthy'=>$healthyOut,'wounded'=>$woundedOut,'dead'=>$deadOut,'prisoners'=>$prisonersOut,'freeSurvivors'=>$healthyOut+$woundedOut],
                'capturable'=>$prepared->unit($type)->capturable,'prisonersSelectedBeforeCompression'=>$selected,'unitCost'=>$cost];
        }
        $lossPercent=$initialCost===0?'0':\App\Game\Combat\Numeric\CombatFixedPoint::formatUnits(intdiv($lostCost*100*\App\Game\Combat\Numeric\CombatFixedPoint::SCALE+intdiv($initialCost,2),$initialCost));
        return ['defeated'=>$defeated,'types'=>$types,'initialCost'=>$initialCost,'economicLoss'=>$lostCost,'economicLossPercent'=>$lossPercent];
    }
}
