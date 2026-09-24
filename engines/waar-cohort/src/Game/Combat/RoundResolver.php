<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\PreparedCombatSide;
use App\Game\Combat\Rules\CombatRuleset;
use App\Game\Random\RandomSource;
use App\Game\Random\AddressedRandom;

final class RoundResolver
{
    /**
     * Resolve one side from immutable start-of-round armies. Reallocation is the
     * inherited cohort behaviour and is deliberately kept pending a later rule decision.
     *
     * @param array<string,string> $accuracyByType
     */
    public function resolveAttacks(
        CombatArmy $actingSnapshot,
        CombatArmy $targetSnapshot,
        PreparedCombatSide $actingPrepared,
        CombatRuleset $ruleset,
        RandomSource $random,
        array $accuracyByType,
        bool $defending = false,
        ?AddressedRandom $addressed = null
    ): AttackResult {
        $target = clone $targetSnapshot;
        $matrix = $this->emptyMatrix($actingPrepared, $ruleset, $accuracyByType, $defending);
        $attempts = $hits = 0;
        $deathsBefore = $target->deadCount();
        foreach (UnitType::cases() as $actingType) {
            $count = $actingSnapshot->livingCount($actingType);
            $strikes = $actingPrepared->unit($actingType)->strikesPerAttack;
            foreach (UnitType::cases() as $targetType) {
                $matrix[$actingType->value][$targetType->value]['sourceCount'] = $count;
            }
            if ($count > intdiv(PHP_INT_MAX, $strikes)) {
                throw new \OverflowException('Strike attempt count exceeds the supported range.');
            }
            $pending = $count * $strikes;
            $attempts += $pending;
            $wave = 0;
            while ($pending > 0 && $target->livingCount() > 0) {
                $reallocated = 0;
                foreach ($this->allocateWeightedLivingTargets($pending, $actingType, $target, $ruleset, $random, $addressed, $wave) as $targetValue => $allocated) {
                    if ($allocated <= 0) {
                        continue;
                    }
                    $targetType = UnitType::from($targetValue);
                    $accuracy = (float)$accuracyByType[$actingType->value];
                    $sampled = $addressed?->binomial($allocated, $accuracy, $actingType->value, "hit/{$wave}/{$targetValue}") ?? $random->binomial($allocated, $accuracy);
                    $perStrike = CombatFixedPoint::divideByInt($actingPrepared->unit($actingType)->attack, $strikes);
                    $damage = CombatFixedPoint::multiply($perStrike, $ruleset->attackFactor($actingType, $targetType));
                    if ($defending) {
                        $damage = CombatFixedPoint::multiply($damage, $actingPrepared->unit($actingType)->defendingEfficiency);
                    }
                    $needed = $this->impactsNeededToDestroy($target, $targetType, $damage);
                    $consumed = $allocated;
                    $applied = $sampled;
                    if (null !== $needed && $sampled >= $needed) {
                        $consumed = max(1, min($allocated, (int)ceil($allocated * $needed / max(1, $sampled))));
                        $applied = $needed;
                        $reallocated += $allocated - $consumed;
                    }
                    $absorbed = $applied > 0 ? $this->applyImpacts($target, $targetType, $applied, $damage, $random, $addressed, $actingType->value, $wave) : 0;
                    $hits += $applied;
                    $cell = &$matrix[$actingType->value][$targetType->value];
                    $cell['allocatedAttempts'] += $allocated;
                    $cell['consumedAttempts'] += $consumed;
                    $cell['reallocatedAttempts'] += $allocated - $consumed;
                    $cell['sampledHits'] += $sampled;
                    $cell['appliedHits'] += $applied;
                    $emitted = CombatFixedPoint::units($damage) * $applied;
                    $cell['damageEmittedUnits'] += $emitted;
                    $cell['damageAbsorbedUnits'] += $absorbed;
                    $cell['overkillUnits'] += max(0, $emitted - $absorbed);
                    unset($cell);
                }
                $pending = $reallocated;
                ++$wave;
            }
        }
        foreach ($matrix as &$row) {
            foreach ($row as &$cell) {
                foreach (['damageEmittedUnits' => 'damageEmitted', 'damageAbsorbedUnits' => 'damageAbsorbed', 'overkillUnits' => 'overkill'] as $units => $name) {
                    $cell[$name] = CombatFixedPoint::formatUnits($cell[$units]);
                    unset($cell[$units]);
                }
            }
        }
        unset($cell,$row);
        return new AttackResult($target, $attempts, $hits, $target->deadCount() - $deathsBefore, $matrix);
    }

    /** @param array<string,string> $accuracyByType @return array<string,array<string,array<string,mixed>>> */
    private function emptyMatrix(PreparedCombatSide $prepared, CombatRuleset $ruleset, array $accuracyByType, bool $defending): array
    {
        $matrix = [];
        foreach (UnitType::cases() as $acting) {
            foreach (UnitType::cases() as $target) {
                $unit = $prepared->unit($acting);
                $perStrike = CombatFixedPoint::divideByInt($unit->attack, $unit->strikesPerAttack);
                $damage = CombatFixedPoint::multiply($perStrike, $ruleset->attackFactor($acting, $target));
                if ($defending) {
                    $damage = CombatFixedPoint::multiply($damage, $unit->defendingEfficiency);
                }
                $matrix[$acting->value][$target->value] = ['sourceCount' => 0, 'strikesPerAttack' => $unit->strikesPerAttack, 'allocatedAttempts' => 0, 'consumedAttempts' => 0, 'reallocatedAttempts' => 0,
                    'sampledHits' => 0, 'appliedHits' => 0, 'attackPerStrike' => CombatFixedPoint::format($perStrike), 'accuracy' => $accuracyByType[$acting->value] ?? '0',
                    'attackFactor' => CombatFixedPoint::format($ruleset->attackFactor($acting, $target)), 'defendingEfficiency' => CombatFixedPoint::format($defending ? $unit->defendingEfficiency : 1),
                    'damagePerHit' => CombatFixedPoint::format($damage), 'damageEmittedUnits' => 0, 'damageAbsorbedUnits' => 0, 'overkillUnits' => 0];
            }
        }
        return $matrix;
    }

    /** @return array<string,int> */
    private function allocateWeightedLivingTargets(int $attempts, UnitType $acting, CombatArmy $target, CombatRuleset $ruleset, RandomSource $random, ?AddressedRandom $addressed = null, int $wave = 0): array
    {
        $types = array_values(array_filter(UnitType::cases(), static fn (UnitType $type) => $target->livingCount($type) > 0));
        $remaining = $attempts;
        if ($addressed !== null) {
            $out = [];
            foreach ($types as $index => $type) {
                $allocated = $index === array_key_last($types) ? $remaining : $addressed->binomial(
                    $remaining,
                    $this->targetProbability(array_slice($types, $index), $acting, $target, $ruleset),
                    $acting->value,
                    "target/{$wave}/{$type->value}"
                );
                $out[$type->value] = $allocated;
                $remaining -= $allocated;
            }
            return $out;
        }
        // Keep the historical arithmetic and random consumption for old replays.
        $weight = array_sum(array_map(fn (UnitType $type) => $target->livingCount($type) * $ruleset->targetWeight($acting, $type), $types));
        $out = [];
        foreach ($types as $index => $type) {
            $typeWeight = $target->livingCount($type) * $ruleset->targetWeight($acting, $type);
            $allocated = $index === array_key_last($types) ? $remaining : $random->binomial($remaining, $typeWeight / $weight);
            $out[$type->value] = $allocated;
            $remaining -= $allocated;
            $weight -= $typeWeight;
        }
        return $out;
    }

    /** @param non-empty-list<UnitType> $types Remaining living targets, in canonical order. */
    private function targetProbability(array $types, UnitType $acting, CombatArmy $target, CombatRuleset $ruleset): float
    {
        // Recompute each conditional denominator: subtracting a dominant weight
        // can erase the tail. Scale preferences BEFORE multiplying by population
        // so every finite positive preference remains usable without overflow.
        $scale = max(array_map(fn (UnitType $type) => $ruleset->targetWeight($acting, $type), $types));
        $weights = array_map(fn (UnitType $type) => $target->livingCount($type) * ($ruleset->targetWeight($acting, $type) / $scale), $types);
        $total = 0.0;
        foreach ($weights as $weight) {
            $total += $weight;
        }
        return $weights[0] / $total;
    }

    private function impactsNeededToDestroy(CombatArmy $army, UnitType $type, float $damage): ?int
    {
        if (CombatFixedPoint::compare($damage, 0) <= 0) {
            return null;
        }
        $needed = 0;
        foreach ($army->cohorts($type) as $cohort) {
            $needed += CombatFixedPoint::ceilRatio($cohort->remainingStructure, $damage) * $cohort->count;
        }
        return $needed;
    }

    /** Return absorbed damage in micro-units. */
    private function applyImpacts(CombatArmy $army, UnitType $type, int $impacts, float $damage, RandomSource $random, ?AddressedRandom $addressed = null, string $acting = '', int $wave = 0): int
    {
        if ($impacts <= 0 || CombatFixedPoint::compare($damage, 0) <= 0) {
            return 0;
        }
        $cohorts = $army->cohorts($type);
        if ($addressed !== null) {
            usort($cohorts, static fn ($a, $b) => CombatFixedPoint::units($a->remainingStructure) <=> CombatFixedPoint::units($b->remainingStructure));
        }
        $before = 0;
        foreach ($cohorts as $cohort) {
            $before += CombatFixedPoint::units($cohort->remainingStructure) * $cohort->count;
        }
        $remainingImpacts = $impacts;
        $remainingUnits = array_sum(array_map(static fn (UnitCohort $c) => $c->count, $cohorts));
        $allocations = [];
        foreach ($cohorts as $index => $cohort) {
            $structure = CombatFixedPoint::units($cohort->remainingStructure);
            $allocated = $index === array_key_last($cohorts) ? $remainingImpacts : ($addressed?->binomial($remainingImpacts, $cohort->count / $remainingUnits, $acting, "impact/{$wave}/{$type->value}/{$structure}") ?? $random->binomial($remainingImpacts, $cohort->count / $remainingUnits));
            $allocations[$index] = $allocated;
            $remainingImpacts -= $allocated;
            $remainingUnits -= $cohort->count;
        }
        $updated = [];
        $deaths = 0;
        foreach ($cohorts as $index => $cohort) {
            $perUnit = intdiv($allocations[$index], $cohort->count);
            $remainder = $allocations[$index] % $cohort->count;
            if ($cohort->count - $remainder > 0) {
                $this->appendDamaged($updated, $deaths, $cohort, $cohort->count - $remainder, $perUnit, $damage);
            }
            if ($remainder > 0) {
                $this->appendDamaged($updated, $deaths, $cohort, $remainder, $perUnit + 1, $damage);
            }
        }
        $army->replaceType($type, $updated, $deaths);
        $after = 0;
        foreach ($army->cohorts($type) as $cohort) {
            $after += CombatFixedPoint::units($cohort->remainingStructure) * $cohort->count;
        }
        return $before - $after;
    }

    /** @param list<UnitCohort> $updated */
    private function appendDamaged(array &$updated, int &$deaths, UnitCohort $source, int $count, int $hits, float $damage): void
    {
        $structure = CombatFixedPoint::subtractRepeated($source->remainingStructure, $hits, $damage);
        if (CombatFixedPoint::compare($structure, 0) <= 0) {
            $deaths += $count;
        } else {
            $updated[] = new UnitCohort($source->type, $structure, $count);
        }
    }
}
