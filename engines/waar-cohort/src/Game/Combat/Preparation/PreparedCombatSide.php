<?php

namespace App\Game\Combat\Preparation;

use App\Game\Army\UnitType;

final readonly class PreparedCombatSide
{
    /** @var array<string,PreparedUnit> */
    private array $units;
    /** @param iterable<PreparedUnit> $units @param list<array<string,mixed>> $modifiers */
    public function __construct(iterable $units, public array $modifiers = [])
    {
        $indexed = [];
        foreach ($units as $unit) {
            $indexed[$unit->type->value] = $unit;
        }
        if (count($indexed) !== count(UnitType::cases())) {
            throw new \InvalidArgumentException('Prepared side needs every unit type.');
        }
        $this->units = $indexed;
    }
    public function unit(UnitType $type): PreparedUnit
    {
        return $this->units[$type->value];
    }
    /** @return list<PreparedUnit> */ public function units(): array
    {
        return array_map(fn (UnitType $t) => $this->unit($t), UnitType::cases());
    }
    /** @return array<string,mixed> */ public function toArray(): array
    {
        return ['units' => array_map(fn (PreparedUnit $u) => $u->toArray(), $this->units()), 'modifiers' => $this->modifiers];
    }
}
