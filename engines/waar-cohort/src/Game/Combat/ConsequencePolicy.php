<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Random\ConsequenceSampler;
use App\Game\Combat\Rules\CombatRuleset;

final class ConsequencePolicy
{
    public const VERSION='wounded-capture-then-compress/2';
    public const PROBABILISTIC_VERSION='wounded-capture-then-compress/3';

    public static function validateVersion(string $version): void
    {
        if (!in_array($version, [self::VERSION, self::PROBABILISTIC_VERSION], true)) throw new \InvalidArgumentException('Unsupported consequence policy.');
    }

    /** [healthy, wounded, dead, prisoners, selected before compression]. Pure per-type projection. */
    public static function counts(int $initial, int $dead, int $wounded, bool $eligible, int $compression, int $capture,
        int $seed, string $side, string $type, string $version = self::VERSION): array
    {
        self::validateVersion($version);
        if ($initial < 0 || $initial > 4294967295 || $dead < 0 || $wounded < 0 || $dead + $wounded > $initial
            || $compression < 0 || $compression > 100 || $capture < 0 || $capture > 50) throw new \InvalidArgumentException('Invalid consequence counts or rates.');
        $draw = static fn(int $n, int $percent, string $stage): int => $version === self::VERSION
            ? intdiv($n * $percent, 100)
            : (new ConsequenceSampler($seed, $side, $type, $stage))->binomial($n, $percent);
        $selected = $eligible ? $draw($wounded, $capture, 'capture') : 0;
        $d = $draw($dead, $compression, 'dead');
        $w = $draw($wounded - $selected, $compression, 'wounded');
        $p = $draw($selected, $compression, 'prisoners');
        return [$initial - $d - $w - $p, $w, $d, $p, $selected];
    }

    /** @return array<string,mixed> */
    public function project(CombatResult $result,CombatRuleset $ruleset,int $compressionPercent,int $capturePercent,string $policyVersion=self::VERSION):array
    {
        if($compressionPercent<0||$compressionPercent>100||$capturePercent<0||$capturePercent>50)throw new \InvalidArgumentException('Compression must be 0..100 and capture 0..50 percent.');
        self::validateVersion($policyVersion);
        return ['schemaVersion'=>'waar-combat-consequences/1','policyVersion'=>$policyVersion,
            ...($policyVersion===self::PROBABILISTIC_VERSION?['samplingProtocol'=>ConsequenceSampler::VERSION]:[]),'rawResult'=>$result->replayHash,
            'compressionPercent'=>$compressionPercent,'capturePercent'=>$capturePercent,
            'attacker'=>$this->side($result->attackerArmy,$result->attackerPrepared,$result->winner===CombatSide::Defender,$compressionPercent,$capturePercent,$result->snapshot->seed,'attacker',$policyVersion),
            'defender'=>$this->side($result->defenderArmy,$result->defenderPrepared,$result->winner===CombatSide::Attacker,$compressionPercent,$capturePercent,$result->snapshot->seed,'defender',$policyVersion)];
    }

    /** @return array<string,mixed> */
    private function side(CombatArmy $army,\App\Game\Combat\Preparation\PreparedCombatSide $prepared,bool $defeated,int $compression,int $capture,int $seed,string $side,string $version):array
    {
        $types=[];$initialCost=$lostCost=0;
        foreach(UnitType::cases() as $type){$initial=$army->initialCount($type);$dead=$army->deadCount($type);$wounded=$army->woundedCount($prepared,$type);$healthy=$initial-$dead-$wounded;
            [$healthyOut,$woundedOut,$deadOut,$prisonersOut,$selected]=self::counts($initial,$dead,$wounded,$defeated&&$prepared->unit($type)->capturable,$compression,$capture,$seed,$side,$type->value,$version);
            $cost=$prepared->unit($type)->cost;$initialCost+=$initial*$cost;$lostCost+=($deadOut+$woundedOut)*$cost;
            $types[$type->value]=['initial'=>$initial,'raw'=>['healthy'=>$healthy,'wounded'=>$wounded,'dead'=>$dead],
                'projected'=>['healthy'=>$healthyOut,'wounded'=>$woundedOut,'dead'=>$deadOut,'prisoners'=>$prisonersOut,'freeSurvivors'=>$healthyOut+$woundedOut],
                'capturable'=>$prepared->unit($type)->capturable,'prisonersSelectedBeforeCompression'=>$selected,'unitCost'=>$cost];
        }
        // Long division avoids overflowing lostCost * 100 * SCALE for large armies.
        $scaled=0;
        if($initialCost>0){$scaled=intdiv($lostCost,$initialCost);$remainder=$lostCost%$initialCost;
            for($digit=0;$digit<8;$digit++){$remainder*=10;$scaled=$scaled*10+intdiv($remainder,$initialCost);$remainder%=$initialCost;}
            if($remainder>=intdiv($initialCost,2)+$initialCost%2)$scaled++;
        }
        $lossPercent=\App\Game\Combat\Numeric\CombatFixedPoint::formatUnits($scaled);
        return ['defeated'=>$defeated,'types'=>$types,'initialCost'=>$initialCost,'economicLoss'=>$lostCost,'economicLossPercent'=>$lossPercent];
    }
}
