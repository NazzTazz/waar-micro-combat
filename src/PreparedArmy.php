<?php

namespace Waar\MicroCombat;

final readonly class PreparedArmy
{
    /** @var array<string, PreparedUnit> */
    private array $unitsByType;

    /** @param iterable<PreparedUnit> $units */
    public function __construct(iterable $units)
    {
        $indexed = [];
        foreach ($units as $unit) {
            if (isset($indexed[$unit->type->value])) {
                throw new \InvalidArgumentException('Prepared unit types must be unique.');
            }
            $indexed[$unit->type->value] = $unit;
        }
        if (count($indexed) !== count(UnitType::cases())) {
            throw new \InvalidArgumentException('A prepared value is required for each of the four unit types.');
        }
        $ordered = [];
        foreach (UnitType::cases() as $type) {
            $ordered[$type->value] = $indexed[$type->value] ?? throw new \InvalidArgumentException('Missing prepared unit type.');
        }
        $this->unitsByType = $ordered;
    }

    public function unit(UnitType $type): PreparedUnit
    {
        return $this->unitsByType[$type->value];
    }

    /** @return list<PreparedUnit> */
    public function units(): array
    {
        return array_values($this->unitsByType);
    }

    public function totalCount(): int
    {
        $total = 0;
        foreach ($this->unitsByType as $unit) {
            $total = FixedPoint::checkedAdd($total, $unit->count);
        }

        return $total;
    }
}
