<?php

namespace Waar\MicroCombat\Bagaar;

/** Chooses a defensible target near a stroke of the two letters on the army/glory plane. */
final class HackerPlan
{
    private const DOTS = [
        [6000, 10], [6000, 20], [6000, 30], [6000, 40],
        [12000, 40], [18000, 35], [12000, 30],
        [26000, 10], [26000, 20], [26000, 30], [26000, 40],
        [32000, 40], [38000, 30], [38000, 20], [32000, 10],
    ];

    public static function choose(array $targets, array $reports, array $costs, int $ownValue, int $tick, array $finished): ?string
    {
        $meanCost = array_sum($costs) / count($costs);
        $candidates = [];
        foreach ($targets as $target) {
            if ($target['kind'] !== 'player' || ($finished[$target['id']] ?? 0) >= 9) {
                continue;
            }
            $report = $reports[$target['id']] ?? null;
            if ($report === null || $tick - ($report['tick'] ?? -100) > 2) {
                continue;
            }
            $estimatedValue = $report['armyTotal'] * $meanCost;
            if ($ownValue < 3 * $estimatedValue) {
                continue;
            }
            $distance = INF;
            foreach (self::DOTS as [$x, $y]) {
                $distance = min($distance, (($estimatedValue - $x) / 6000) ** 2 + (($target['glory'] - $y) / 10) ** 2);
            }
            $candidates[] = [$distance, $target['id']];
        }
        sort($candidates);
        return $candidates[0][1] ?? null;
    }
}
