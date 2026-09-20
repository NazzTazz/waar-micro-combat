<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class UnitCohort
{
    public float $remainingStructure;

    public function __construct(
        public UnitType $type,
        float $remainingStructure,
        public int $count,
    ) {
        if ($count <= 0) {
            throw new \InvalidArgumentException('A cohort count must be strictly positive.');
        }
        $this->remainingStructure = CombatFixedPoint::canonicalize($remainingStructure);
    }

    public function state(float $maximumStructure): UnitState
    {
        if (CombatFixedPoint::compare($this->remainingStructure, 0) <= 0) {
            return UnitState::Dead;
        }
        return CombatFixedPoint::compare($this->remainingStructure, $maximumStructure) < 0 ? UnitState::Wounded : UnitState::Valid;
    }
}
