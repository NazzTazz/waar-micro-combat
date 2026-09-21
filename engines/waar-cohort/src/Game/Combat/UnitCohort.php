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

    public function state(float $maximumStructure, float|int|string $woundDamageThreshold = 0): UnitState
    {
        if (CombatFixedPoint::compare($this->remainingStructure, 0) <= 0) {
            return UnitState::Dead;
        }
        $maximum = CombatFixedPoint::units($maximumStructure);
        $remaining = CombatFixedPoint::units($this->remainingStructure);
        $threshold = CombatFixedPoint::units($woundDamageThreshold);
        if ($maximum <= 0 || $threshold < 0 || $threshold > CombatFixedPoint::SCALE) {
            throw new \InvalidArgumentException('Wound classification expects positive structure and a threshold between 0 and 1.');
        }

        return CombatFixedPoint::compareProducts(
            $maximum - $remaining,
            CombatFixedPoint::SCALE,
            $threshold,
            $maximum,
        ) > 0 ? UnitState::Wounded : UnitState::Valid;
    }
}
