<?php

namespace Waar\MicroCombat\Bagaar;

/** Applies one verified cohort result to virtual Waar accounts. No combat is resolved here. */
final class CombatTransition
{
    public static function apply(array $attacker, array $defender, array $report, int $lootGold, bool $village = false): array
    {
        if (($report['result']['schemaVersion'] ?? null) !== 'waar-combat-result/2'
            || ($report['consequences']['schemaVersion'] ?? null) !== 'waar-combat-consequences/1') {
            throw new \InvalidArgumentException('Résultat du moteur cohortes invalide.');
        }
        $winner = $report['result']['winner'] ?? null;
        if (!in_array($winner, ['attacker', 'defender', null], true) || $lootGold < 0) {
            throw new \InvalidArgumentException('Vainqueur ou pillage invalide.');
        }
        if ($winner !== 'attacker' && $lootGold !== 0) {
            throw new \InvalidArgumentException('Pillage réservé à une victoire attaquante.');
        }
        if (!HostRules::canAttack($attacker['glory'], $defender['glory'], $attacker['attacks'], $defender['defenses'])) {
            throw new \DomainException('Combat interdit par la portée ou les quotas.');
        }
        if (array_sum($attacker['army']) < 1) {
            throw new \DomainException('Armée attaquante vide.');
        }
        $captured = ['attacker' => 0, 'defender' => 0];
        $losses = ['attacker' => [], 'defender' => []];
        foreach (['attacker', 'defender'] as $side) {
            $player = $side === 'attacker' ? $attacker : $defender;
            $units = $report['consequences'][$side]['types'] ?? null;
            if (!is_array($units)) {
                throw new \InvalidArgumentException('Conséquences par type manquantes.');
            }
            $hospitalRoom = max(0, 100 * $player['hospitalLevel'] - array_sum($player['hospital']));
            foreach (['soldier', 'spearman', 'knight', 'archer'] as $type) {
                $projected = $units[$type]['projected'] ?? null;
                if (!is_array($projected) || ($units[$type]['initial'] ?? null) !== $player['army'][$type]) {
                    throw new \InvalidArgumentException('Effectifs initiaux incompatibles avec le compte.');
                }
                foreach (['healthy', 'wounded', 'dead', 'prisoners'] as $key) {
                    if (!is_int($projected[$key] ?? null) || $projected[$key] < 0) {
                        throw new \InvalidArgumentException('Conséquences invalides.');
                    }
                }
                if (array_sum(array_intersect_key($projected, array_flip(['healthy', 'wounded', 'dead', 'prisoners']))) !== $player['army'][$type]) {
                    throw new \InvalidArgumentException('Conservation des effectifs rompue.');
                }
                $losses[$side][$type] = ['dead' => $projected['dead'], 'wounded' => $projected['wounded'],
                    'prisoners' => $projected['prisoners']];
                $player['army'][$type] = $projected['healthy'];
                $admitted = min($hospitalRoom, $projected['wounded']);
                $player['hospital'][$type] += $admitted;
                $hospitalRoom -= $admitted;
                $captured[$side] += $projected['prisoners'];
            }
            if ($side === 'attacker') {
                $attacker = $player;
            } else {
                $defender = $player;
            }
        }
        if ($winner === null && ($captured['attacker'] !== 0 || $captured['defender'] !== 0)) {
            throw new \InvalidArgumentException('Un nul ne peut capturer de prisonniers.');
        }
        $attacker['attacks']--;
        $defender['defenses']--;
        $attacker['defenseLossStreak'] = 0; // An attack report interrupts a defense-loss series.
        $loot = 0;
        if ($winner === 'attacker') {
            $production = HostRules::mineProduction($defender['mineLevel']);
            $protected = (int) floor($production * (1 + 0.1 * ($defender['protectionLevel'] ?? 0)));
            $unprotected = max(0, $defender['gold'] - $protected);
            if ($lootGold < (int) ($unprotected * 0.1) || $lootGold > (int) ($unprotected * 0.15)) {
                throw new \InvalidArgumentException('Pillage hors des bornes Waar.');
            }
            $loot = $lootGold;
            $defender['gold'] -= $loot;
            $attacker['gold'] += $loot;
            $attacker['glory'] += 1;
            $attacker['prisoners'] += $captured['defender'];
            $attacker['morale'] = min(110, $attacker['morale'] + 1);
        } elseif ($winner === 'defender') {
            $defender['prisoners'] += $captured['attacker'];
            $defender['morale'] = min(110, $defender['morale'] + 1);
        }
        $surrendersBefore = $defender['surrenders'];
        if (!$village) {
            $defender = HostRules::reportDefenseResult($defender, $winner === 'attacker');
        }
        return ['attacker' => $attacker, 'defender' => $defender, 'event' => [
            'winner' => $winner, 'loot' => $loot, 'prisoners' => $winner === 'attacker' ? $captured['defender'] : ($winner === 'defender' ? $captured['attacker'] : 0),
            'surrender' => $defender['surrenders'] > $surrendersBefore,
            'report' => [
                'attacker' => ['types' => $losses['attacker'], 'prisonersCaptured' => $winner === 'attacker' ? $captured['defender'] : 0],
                'defender' => ['types' => $losses['defender'], 'prisonersCaptured' => $winner === 'defender' ? $captured['attacker'] : 0],
            ],
        ]];
    }
}
