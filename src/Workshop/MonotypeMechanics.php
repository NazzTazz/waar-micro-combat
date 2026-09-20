<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\FixedPoint;

/** Descriptive arithmetic only: no combat, targeting or outcome prediction. */
final class MonotypeMechanics
{
    public function describe(EngineProfile $profile, string $weather, string $scenario): array
    {
        [$attacker, $defender] = self::types($scenario);
        if (!in_array($weather, EngineProfile::WEATHER, true)) throw new \InvalidArgumentException('Météo inconnue.');
        $result = [];
        foreach (['attacker'=>[$attacker, $defender], 'defender'=>[$defender, $attacker]] as $side=>[$acting, $target]) {
            $unit = $profile->units[$acting];
            $attack = $this->multiply(FixedPoint::parse($unit['attack']), FixedPoint::parse($profile->weather[$weather][$acting]['attack']));
            $accuracy = $this->multiply(FixedPoint::parse($unit['baseAccuracy']), FixedPoint::parse($profile->weather[$weather][$acting]['baseAccuracy']));
            $spread = FixedPoint::parse($unit['accuracySpread']);
            $factor = FixedPoint::SCALE;
            foreach ($profile->relations as $relation) if ($relation['acting'] === $acting && $relation['target'] === $target) $factor = FixedPoint::parse($relation['factor']);
            // Same half-up boundaries and operation order as v2::empty_matrix:
            // prepared attack / strikes, then directed counter, then defending factor.
            $perStrike = FixedPoint::mulDivNearest($attack, 1, $unit['strikesPerAttack']);
            $attackingDamage = $this->multiply($perStrike, $factor);
            $defendingDamage = $this->multiply($attackingDamage, FixedPoint::parse($unit['defendingEfficiency']));
            $structure = FixedPoint::parse($profile->units[$target]['structure']);
            $damage = $side === 'attacker' ? $attackingDamage : $defendingDamage;
            $result[$side] = [
                'unitType'=>$acting, 'targetType'=>$target, 'role'=>$side,
                'preparedAttack'=>FixedPoint::format($attack), 'attackPerStrike'=>FixedPoint::format($perStrike),
                'strikesPerAttack'=>$unit['strikesPerAttack'], 'baseAccuracy'=>FixedPoint::format($accuracy),
                'accuracyLower'=>FixedPoint::format(max(0, $accuracy - $spread)),
                'accuracyUpper'=>FixedPoint::format(min(FixedPoint::SCALE, $accuracy + $spread)),
                'attackFactor'=>FixedPoint::format($factor), 'defendingEfficiency'=>$unit['defendingEfficiency'],
                'damagePerHit'=>FixedPoint::format($damage), 'damageWhenAttacking'=>FixedPoint::format($attackingDamage),
                'damageWhenDefending'=>FixedPoint::format($defendingDamage), 'targetStructure'=>FixedPoint::format($structure),
                'hitsToKillIntact'=>$this->hits($structure, $damage),
                'hitsWhenAttacking'=>$this->hits($structure, $attackingDamage),
                'hitsWhenDefending'=>$this->hits($structure, $defendingDamage),
            ];
        }
        return $result;
    }

    public static function types(string $scenario): array
    {
        $parts = explode('-vs-', $scenario);
        if (count($parts) !== 2 || !isset(EngineProfile::UNIT_COSTS[$parts[0]], EngineProfile::UNIT_COSTS[$parts[1]])) {
            throw new \InvalidArgumentException('Confrontation monotype inconnue.');
        }
        return $parts;
    }

    private function multiply(int $a, int $b): int { return FixedPoint::mulDivNearest($a, $b, FixedPoint::SCALE); }
    private function hits(int $structure, int $damage): ?int { return $damage === 0 ? null : intdiv($structure + $damage - 1, $damage); }
}
