<?php

namespace Waar\MicroCombat;

final readonly class SideOutcome
{
    /** @var array<string, UnitOutcome> */
    private array $unitsByType;

    /** @param iterable<UnitOutcome> $units */
    public function __construct(iterable $units)
    {
        $indexed = [];
        foreach ($units as $unit) {
            $indexed[$unit->type->value] = $unit;
        }
        $this->unitsByType = $indexed;
    }

    public function unit(UnitType $type): UnitOutcome
    {
        return $this->unitsByType[$type->value];
    }

    /** @return array{survivors: array{numerator: int, denominator: int}, structure: array{numerator: int, denominator: int}, economicValue: array{numerator: int, denominator: int}} */
    public function metrics(): array
    {
        $initialCount = $survivors = $initialStructure = $remainingStructure = $initialValue = $remainingValue = 0;
        foreach ($this->unitsByType as $unit) {
            $initialCount = FixedPoint::checkedAdd($initialCount, $unit->initial);
            $survivors = FixedPoint::checkedAdd($survivors, $unit->survivors);
            $initialStructure = FixedPoint::checkedAdd($initialStructure, $unit->initialStructureMicro);
            $remainingStructure = FixedPoint::checkedAdd($remainingStructure, $unit->remainingStructureMicro);
            $initialValue = FixedPoint::checkedAdd($initialValue, FixedPoint::checkedMultiply($unit->initial, $unit->cost));
            $remainingValue = FixedPoint::checkedAdd($remainingValue, FixedPoint::checkedMultiply($unit->survivors, $unit->cost));
        }

        return [
            'survivors' => ['numerator' => $survivors, 'denominator' => $initialCount],
            'structure' => ['numerator' => $remainingStructure, 'denominator' => $initialStructure],
            'economicValue' => ['numerator' => $remainingValue, 'denominator' => $initialValue],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'units' => array_map(static fn (UnitOutcome $unit): array => $unit->toArray(), $this->unitsByType),
            'metrics' => $this->metrics(),
        ];
    }
}
