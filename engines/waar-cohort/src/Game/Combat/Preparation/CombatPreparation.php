<?php

namespace App\Game\Combat\Preparation;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Rules\CombatRuleset;

final readonly class CombatPreparation
{
    /** @param iterable<CombatModifier|array<string,mixed>> $input */
    public function prepare(CombatRuleset $ruleset, iterable $input = []): PreparedCombatSide
    {
        $modifiers = [];
        $seen = [];
        foreach ($input as $value) {
            $modifier = $value instanceof CombatModifier ? $value : CombatModifier::fromArray($value);
            $key = $modifier->source."\0".$modifier->id;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Duplicate modifier: {$modifier->source}/{$modifier->id}");
            }
            $seen[$key] = true;
            $modifiers[] = $modifier;
        }
        usort($modifiers, static fn (CombatModifier $a, CombatModifier $b) => [$a->source === 'weather', $a->source, $a->id, $a->unitType->value, $a->parameter] <=> [$b->source === 'weather', $b->source, $b->id, $b->unitType->value, $b->parameter]);
        $units = [];
        $serialized = array_map(fn (CombatModifier $m) => $m->toArray(), $modifiers);
        foreach (UnitType::cases() as $type) {
            $base = $ruleset->unit($type);
            $by = [];
            $bool = [];
            foreach ($modifiers as $modifier) {
                if ($modifier->unitType === $type) {
                    if ($modifier->parameter === 'capturable') {
                        $bool[] = $modifier;
                    } else {
                        $by[$modifier->parameter][] = $modifier;
                    }
                }
            }
            $decimal = fn (string $parameter, float $value): float => $this->decimal($value, $by[$parameter] ?? []);
            $integer = fn (string $parameter, int $value): int => $this->integer($value, $by[$parameter] ?? [], $parameter);
            $capturable = $base->capturable;
            if ($bool) {
                $values = array_unique(array_map(fn (CombatModifier $m) => (int)$m->value, $bool));
                if (count($values) > 1) {
                    throw new \InvalidArgumentException("Conflicting capturable modifiers for {$type->value}.");
                }
                $capturable = (bool)reset($values);
            }
            $effects = array_values(array_filter($serialized, fn (array $m) => $m['unitType'] === $type->value));
            $unit = new PreparedUnit(
                $type,
                $decimal('attack', $base->attack),
                $decimal('structure', $base->structure),
                $integer('cost', $base->cost),
                $decimal('baseAccuracy', $base->baseAccuracy),
                $decimal('accuracySpread', $base->accuracySpread),
                $integer('strikesPerAttack', $base->strikesPerAttack),
                $decimal('defendingEfficiency', $base->defendingEfficiency),
                $capturable,
                $base->toArray(),
                $effects
            );
            if ($unit->structure <= 0 || $unit->structure > 1000 || $unit->cost < 1 || $unit->cost > 400400 || $unit->baseAccuracy < 0 || $unit->baseAccuracy > 1
                || $unit->accuracySpread < 0 || $unit->accuracySpread > 1 || $unit->strikesPerAttack < 1 || $unit->strikesPerAttack > 32 || $unit->defendingEfficiency < 0 || $unit->defendingEfficiency > 10 || $unit->attack < 0 || $unit->attack > 1000) {
                throw new \InvalidArgumentException("Prepared value outside supported range for {$type->value}.");
            }
            $units[] = $unit;
        }
        return new PreparedCombatSide($units, $serialized);
    }

    /** @param list<CombatModifier> $modifiers */
    private function decimal(float $base, array $modifiers): float
    {
        return CombatFixedPoint::multiplyMany([$base, ...array_map(fn (CombatModifier $m) => $m->value, $modifiers)]);
    }
    /** @param list<CombatModifier> $modifiers */
    private function integer(int $base, array $modifiers, string $parameter): int
    {
        $units = CombatFixedPoint::multiplyManyUnits([$base, ...array_map(fn (CombatModifier $m) => $m->value, $modifiers)]);
        if ($units % CombatFixedPoint::SCALE !== 0) {
            throw new \InvalidArgumentException("Prepared {$parameter} must remain an integer.");
        }
        return intdiv($units, CombatFixedPoint::SCALE);
    }
}
