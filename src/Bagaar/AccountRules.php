<?php

namespace Waar\MicroCombat\Bagaar;

/** Pure account transitions. The simulator supplies random draws and effective era parameters. */
final class AccountRules
{
    public static function initial(string $id, string $policy): array
    {
        return ['id' => $id, 'policy' => $policy, 'gold' => 2000, 'glory' => 0,
            'mineLevel' => 0, 'hospitalLevel' => 0, 'protectionLevel' => 0,
            'army' => HostRules::emptyArmy(), 'hospital' => HostRules::emptyArmy(),
            'prisoners' => 0, 'prisonerTreatment' => 100, 'morale' => 100,
            'attacks' => 9, 'defenses' => 3, 'autoSurrender' => false,
            'defenseLossStreak' => 0, 'surrenders' => 0, 'spies' => [], 'villageFailures' => [],
            'status' => 'active', 'pauseUntil' => null, 'pauses' => 0, 'recentCombats' => [],
            'rageTarget' => null, 'rageUntil' => 0,
            'cyclePhase' => $policy === 'ascenseur' ? 'build' : null, 'peakArmyGold' => 0,
            'record' => ['wins' => 0, 'draws' => 0, 'losses' => 0]];
    }

    public static function buyMine(array $account): array
    {
        $offer = HostRules::mineUpgrade($account['mineLevel']);
        if ($account['gold'] < $offer['gold'] || $account['glory'] < $offer['glory']) {
            throw new \DomainException('Mine inaccessible.');
        }
        $account['gold'] -= $offer['gold'];
        $account['mineLevel'] = $offer['level'];
        return $account;
    }

    public static function buyHospital(array $account): array
    {
        $offer = HostRules::hospitalUpgrade($account['hospitalLevel']);
        if ($account['gold'] < $offer['gold'] || $account['glory'] < $offer['glory']) {
            throw new \DomainException('Infirmerie inaccessible.');
        }
        $account['gold'] -= $offer['gold'];
        $account['hospitalLevel'] = $offer['level'];
        return $account;
    }

    public static function recruit(array $account, array $units, array $costs): array
    {
        foreach (HostRules::UNIT_TYPES as $type) {
            if (!is_int($units[$type] ?? 0) || ($units[$type] ?? 0) < 0) {
                throw new \InvalidArgumentException('Recrutement invalide.');
            }
        }
        $cost = HostRules::armyValue($units, $costs);
        if ($cost === 0 || $cost > $account['gold']) {
            throw new \DomainException('Recrutement impossible.');
        }
        $account['gold'] -= $cost;
        foreach (HostRules::UNIT_TYPES as $type) {
            $account['army'][$type] += $units[$type] ?? 0;
        }
        return $account;
    }

    /** Heals the largest affordable proportional group, as the host's infirmary action does. */
    public static function heal(array $account, array $costs): array
    {
        if ($account['hospitalLevel'] < 1 || array_sum($account['hospital']) === 0) {
            throw new \DomainException('Aucun soin possible.');
        }
        $fullCost = self::healingCost($account['hospital'], $costs);
        $ratio = $fullCost <= $account['gold'] ? 1.0 : $account['gold'] / max(1, $fullCost);
        $healed = self::proportion($account['hospital'], $ratio);
        while ($ratio > 0 && self::healingCost($healed, $costs) > $account['gold']) {
            $ratio = max(0, $ratio - 0.001);
            $healed = self::proportion($account['hospital'], $ratio);
        }
        if (array_sum($healed) === 0) {
            throw new \DomainException('Or insuffisant pour soigner une unité.');
        }
        $account['gold'] -= self::healingCost($healed, $costs);
        foreach (HostRules::UNIT_TYPES as $type) {
            $account['hospital'][$type] -= $healed[$type];
            $account['army'][$type] += $healed[$type];
        }
        return $account;
    }

    public static function spy(array $account, array $target, int $range): array
    {
        $price = HostRules::espionageCost($account['glory']);
        if ($account['id'] === $target['id'] || abs($account['glory'] - $target['glory']) > $range || $account['gold'] < $price) {
            throw new \DomainException('Espionnage interdit.');
        }
        $account['gold'] -= $price;
        $account['spies'][$target['id']] = HostRules::espionageReport($target);
        return $account;
    }

    public static function hourly(array $account, int $outsideLossPercent, float $mineRatio = 1.0, float $prisonerProductionRatio = 1.0, float $prisonerLossDivisor = 1.0): array
    {
        if ($outsideLossPercent < 40 || $outsideLossPercent > 80 || $mineRatio <= 0 || $prisonerProductionRatio <= 0 || $prisonerLossDivisor <= 0) {
            throw new \InvalidArgumentException('Paramètres horaires invalides.');
        }
        $account = self::hospitalAttrition($account, $outsideLossPercent);
        $prisoners = $account['prisoners'];
        $treatment = $account['prisonerTreatment'];
        $prisonerProduction = $prisoners === 0 ? 0 : (int) ceil(($prisoners / ($treatment / 100 * 16 + 4))
            / log(log($prisoners + 2)) * (1 + $account['mineLevel'] / 100) * $prisonerProductionRatio);
        $prisonerLoss = (int) ceil($prisoners * ((1 - $treatment / 100) * 0.25 + 0.05) / $prisonerLossDivisor);
        $account['gold'] += HostRules::mineProduction($account['mineLevel'], $mineRatio) + $prisonerProduction;
        $account['prisoners'] = max(0, $prisoners - $prisonerLoss);
        $quotas = HostRules::quotas($account['army']);
        $account['attacks'] = $quotas['attacks'];
        $account['defenses'] = $quotas['defenses'];
        return $account;
    }

    private static function hospitalAttrition(array $account, int $outsideLossPercent): array
    {
        $hospital = $account['hospital'];
        $total = array_sum($hospital);
        if ($total === 0) {
            return $account;
        }
        $capacity = $account['hospitalLevel'] * 100;
        $outside = max(0, $total - $capacity);
        $inside = min($total, $capacity);
        $insideLossBudget = ($capacity - $inside) * 0.1 + 5;
        foreach (HostRules::UNIT_TYPES as $type) {
            $ratio = $hospital[$type] / $total;
            $loss = (int) floor($outside * $ratio * $outsideLossPercent / 100)
                + (int) floor($insideLossBudget * $ratio);
            $account['hospital'][$type] = max(0, $hospital[$type] - $loss);
        }
        return $account;
    }

    private static function healingCost(array $army, array $costs): int
    {
        return (int) (HostRules::armyValue($army, $costs) / 1.1);
    }

    private static function proportion(array $army, float $ratio): array
    {
        $out = [];
        foreach (HostRules::UNIT_TYPES as $type) {
            $out[$type] = (int) floor($army[$type] * $ratio);
        }
        return $out;
    }
}
