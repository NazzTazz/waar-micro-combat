<?php

namespace Waar\MicroCombat\Bagaar;

/** Pure Waar rules used by the virtual era. All prices for troops come from the selected preset. */
final class HostRules
{
    public const ATTACK_RANGE = 20;
    public const SURRENDER_LOSSES = 9;
    public const UNIT_TYPES = ['soldier', 'spearman', 'archer', 'knight'];

    public static function emptyArmy(): array
    {
        return array_fill_keys(self::UNIT_TYPES, 0);
    }

    public static function armyValue(array $army, array $costs): int
    {
        $value = 0;
        foreach (self::UNIT_TYPES as $type) {
            $count = $army[$type] ?? 0;
            $cost = $costs[$type] ?? null;
            if (!is_int($count) || $count < 0 || !is_int($cost) || $cost < 1) {
                throw new \InvalidArgumentException('Armée ou prix du preset invalide.');
            }
            $value += $count * $cost;
        }
        return $value;
    }

    public static function mineUpgrade(int $currentLevel): array
    {
        $next = $currentLevel + 1;
        return ['level' => $next, 'gold' => (int) floor($next ** 2.5 * 8), 'glory' => 20 * max($next - 8, 0)];
    }

    public static function mineProduction(int $level, float $eventRatio = 1.0): int
    {
        return (int) floor(($level / 1.5) ** 2 * 20) * $eventRatio;
    }

    public static function hospitalUpgrade(int $currentLevel): array
    {
        $next = $currentLevel + 1;
        $price = 1000;
        for ($level = 2; $level <= $next; $level++) {
            $price = (int) (1000 * $level + $price / 2);
        }
        return ['level' => $next, 'gold' => $price, 'glory' => 5 * ($next - 1)];
    }

    public static function quotas(array $army, int $baseAttacks = 9, int $baseDefenses = 3): array
    {
        $total = array_sum($army);
        if ($total === 0) {
            return ['attacks' => $baseAttacks, 'defenses' => $baseDefenses];
        }
        $bonus = static fn (int $count): int => (int) floor(max(0, ($count / $total - 0.25) / 0.05));
        return ['attacks' => $baseAttacks + $bonus($army['knight'] ?? 0), 'defenses' => $baseDefenses + $bonus($army['spearman'] ?? 0)];
    }

    public static function canAttack(int $attackerGlory, int $defenderGlory, int $attacks, int $defenses): bool
    {
        return abs($attackerGlory - $defenderGlory) <= self::ATTACK_RANGE && $attacks > 0 && $defenses > 0;
    }

    public static function espionageCost(int $glory): int
    {
        return (int) round($glory / 2.5);
    }

    /** The report deliberately excludes opponent composition and policy. */
    public static function espionageReport(array $target): array
    {
        return ['gold' => $target['gold'], 'armyTotal' => array_sum($target['army']), 'glory' => $target['glory'], 'morale' => $target['morale'] >= 100 ? 'high' : 'low'];
    }

    /** Non-defensive-loss combat reports interrupt the consecutive streak. */
    public static function reportDefenseResult(array $player, bool $lostDefense): array
    {
        $player['defenseLossStreak'] = $lostDefense ? $player['defenseLossStreak'] + 1 : 0;
        if ($player['autoSurrender'] && $player['defenseLossStreak'] >= self::SURRENDER_LOSSES) {
            return self::surrender($player);
        }
        return $player;
    }

    public static function surrender(array $player): array
    {
        $player['glory'] = max(0, $player['glory'] - 30);
        $player['prisoners'] = 0;
        $player['morale'] = 100;
        $player['defenseLossStreak'] = 0;
        $player['surrenders']++;
        return $player;
    }

    public static function manualSurrender(array $player): array
    {
        if ($player['defenseLossStreak'] < self::SURRENDER_LOSSES) {
            throw new \DomainException('Reddition indisponible avant neuf défaites défensives consécutives.');
        }
        return self::surrender($player);
    }
}
