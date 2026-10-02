<?php

namespace Waar\MicroCombat\Bagaar;

/** Raw ratios read from a player snapshot. They describe the account; they do not choose an action. */
final class DimensionlessSignals
{
    public const VERSION = 'bagaar-signals/1';

    /**
     * A null ratio is undefined. Zero is a measured value. Terms are not renormalized into shares.
     *
     * @return array{signalsVersion:string,gloryRank:int,activeGloryRank:?int,R_picsou:?float,R_joy:?float,R_rage:?float,R_eq:?float,R_rwaa:?float,R_offense:?float,R_defense:?float,defined:array{R_picsou:bool,R_joy:bool,R_rage:bool,R_eq:bool,R_rwaa:bool,R_offense:bool,R_defense:bool}}
     */
    public static function from(
        int|float $powerDestroyed,
        int|float $powerLost,
        int|float $armyGold,
        int|float $goldLooted,
        int $wins,
        int $draws,
        int $losses,
        int $mineLevel,
        int|float $mineProduction,
        int|float $glory,
        int $gloryRank,
        ?int $activeGloryRank,
    ): array {
        $destroyed = (float) $powerDestroyed;
        $lost = (float) $powerLost;
        $army = (float) $armyGold;
        $looted = (float) $goldLooted;
        $gloryValue = (float) $glory;
        $mine = (float) $mineProduction;
        $fights = $wins + $draws + $losses;
        $winRate = $fights > 0 ? $wins / $fights : null;
        $lossRate = $fights > 0 ? $losses / $fights : null;
        $strike = self::measure($destroyed / ($lost + 1));
        $hurt = self::measure($lost / ($destroyed + 1));

        $picsou = $mine > 0 && $strike !== null ? self::measure($strike * $army / $mine) : null;
        $equilibrium = $winRate === null ? null : self::measure(
            (($mineLevel + 1) / ($gloryValue / 20 + 1)) * $winRate * (($destroyed + $looted) / ($lost + 1)),
        );
        $rwaa = $winRate === null ? null : self::measure($gloryValue * $winRate);
        $joy = $winRate === null || $strike === null ? null : self::measure($winRate * $strike * ($looted / ($lost + 1)));
        $rage = $lossRate === null || $hurt === null ? null : self::measure(
            $lossRate * $hurt / ($looted + 1) * ($lost / max($gloryValue, 10)),
        );
        $offense = $winRate === null || $strike === null ? null : self::measure($winRate * $strike);
        $defense = $lossRate === null || $mine <= 0 ? null : self::measure($lossRate * $army / $mine);
        $values = [
            'R_picsou' => $picsou,
            'R_joy' => $joy,
            'R_rage' => $rage,
            'R_eq' => $equilibrium,
            'R_rwaa' => $rwaa,
            'R_offense' => $offense,
            'R_defense' => $defense,
        ];

        return [
            'signalsVersion' => self::VERSION,
            'gloryRank' => $gloryRank,
            'activeGloryRank' => $activeGloryRank,
            ...$values,
            'defined' => array_map(static fn (?float $value): bool => $value !== null, $values),
        ];
    }

    private static function measure(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }
}
