<?php

namespace Waar\MicroCombat\Bagaar;

/** Virtual counterpart of Waar's on-demand village tiers and hourly refill. */
final class VillageRules
{
    public static function availableTiers(int $visitorGlory, int $leaderGlory, int $margin = 20): array
    {
        $step = HostRules::ATTACK_RANGE;
        $cap = (int) floor(($leaderGlory - $step - $margin) / $step) * $step;
        $min = max($step, (int) ceil(($visitorGlory - $step) / $step) * $step);
        $max = min($cap, (int) floor(($visitorGlory + $step) / $step) * $step);
        $tiers = [];
        for ($tier = $min; $tier <= $max; $tier += $step) {
            $tiers[] = $tier;
        }
        return $tiers;
    }

    public static function caps(int $tier, array $players, array $costs): array
    {
        $level = 8 + intdiv($tier, 20);
        $hourlyProduction = HostRules::mineProduction($level);
        $values = [];
        foreach ($players as $player) {
            if (abs($player['glory'] - $tier) <= HostRules::ATTACK_RANGE) {
                $values[] = HostRules::armyValue($player['army'], $costs);
            }
        }
        $averageValue = $values === [] ? 0 : array_sum($values) / count($values);
        $share = 0.6 + 0.4 * min(1, $tier / 260);
        $budget = max(10 * $hourlyProduction, $share * $averageValue);
        $mix = self::mix($tier);
        $averageCost = 0;
        foreach ($mix as $type => $ratio) {
            $averageCost += $ratio * $costs[$type];
        }
        $total = max(1, (int) round($budget / $averageCost));
        return ['army' => self::army($tier, $total), 'gold' => 24 * $hourlyProduction];
    }

    public static function refill(array $village, array $caps): array
    {
        $current = array_sum($village['army']);
        $limit = array_sum($caps['army']);
        $next = $current < $limit ? min($limit, $current + (int) ceil($limit * 0.25)) : $limit;
        $village['army'] = self::army($village['glory'], $next);
        $village['gold'] = min($caps['gold'], $village['gold'] + (int) ceil($caps['gold'] * 0.10));
        return $village;
    }

    private static function mix(int $tier): array
    {
        $progress = min(1, $tier / 300);
        $spearman = 0.10 + 0.40 * $progress;
        $knight = 0.15 * $progress;
        return ['soldier' => 1 - $spearman - $knight, 'spearman' => $spearman, 'knight' => $knight];
    }

    private static function army(int $tier, int $total): array
    {
        $mix = self::mix($tier);
        $spearman = (int) floor($total * $mix['spearman']);
        $knight = (int) floor($total * $mix['knight']);
        return ['soldier' => $total - $spearman - $knight, 'spearman' => $spearman, 'archer' => 0, 'knight' => $knight];
    }
}
