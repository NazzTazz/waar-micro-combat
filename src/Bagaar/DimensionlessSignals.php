<?php

namespace Waar\MicroCombat\Bagaar;

/** Ratios read from a player snapshot. They describe the account; they do not choose an action. */
final class DimensionlessSignals
{
    /**
     * Each family sums to 1. A family whose raw terms are all zero is shared equally.
     *
     * @return array{gloryRank:int,R_picsou:float,R_joy:float,R_rage:float,R_eq:float,R_rwaa:float,R_offense:float,R_defense:float}
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
    ): array {
        $fights = $wins + $draws + $losses;
        $winRate = $fights > 0 ? $wins / $fights : 0.0;
        $lossRate = $fights > 0 ? $losses / $fights : 0.0;
        $mine = $mineProduction > 0 ? (float) $mineProduction : 0.0;
        $strike = $powerDestroyed / ($powerLost + 1);
        $hurt = $powerLost / ($powerDestroyed + 1);

        [$picsou, $equilibrium, $rwaa] = self::share(
            $mine > 0 ? $strike * $armyGold / $mine : 0.0,
            (($mineLevel + 1) / ($glory / 20 + 1)) * $winRate * (($powerDestroyed + $goldLooted) / ($powerLost + 1)) * $glory,
            $glory * $winRate,
        );
        [$joy, $rage] = self::share(
            $winRate * $strike * ($goldLooted / ($powerLost + 1)),
            $lossRate * $hurt / ($goldLooted + 1) * ($powerLost / max($glory, 10)),
        );
        [$offense, $defense] = self::share(
            $winRate * $strike,
            $mine > 0 ? $lossRate * $armyGold / $mine : 0.0,
        );

        return [
            'gloryRank' => $gloryRank,
            'R_picsou' => $picsou,
            'R_joy' => $joy,
            'R_rage' => $rage,
            'R_eq' => $equilibrium,
            'R_rwaa' => $rwaa,
            'R_offense' => $offense,
            'R_defense' => $defense,
        ];
    }

    /** @param float ...$parts @return list<float> */
    private static function share(float ...$parts): array
    {
        $safe = array_map(static fn (float $part): float => is_finite($part) && $part > 0 ? $part : 0.0, $parts);
        $total = array_sum($safe);
        if ($total <= 0.0) {
            return array_fill(0, count($parts), 1.0 / count($parts));
        }

        return array_map(static fn (float $part): float => $part / $total, $safe);
    }
}
