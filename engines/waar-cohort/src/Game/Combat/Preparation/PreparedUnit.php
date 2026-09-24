<?php

namespace App\Game\Combat\Preparation;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class PreparedUnit
{
    /** @param array<string,mixed> $base @param list<array<string,mixed>> $effects */
    public function __construct(
        public UnitType $type,
        public float $attack,
        public float $structure,
        public int $cost,
        public float $baseAccuracy,
        public float $accuracySpread,
        public int $strikesPerAttack,
        public float $defendingEfficiency,
        public bool $capturable,
        public array $base,
        public array $effects,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'attack' => CombatFixedPoint::format($this->attack),
            'structure' => CombatFixedPoint::format($this->structure), 'cost' => $this->cost,
            'baseAccuracy' => CombatFixedPoint::format($this->baseAccuracy), 'accuracySpread' => CombatFixedPoint::format($this->accuracySpread),
            'strikesPerAttack' => $this->strikesPerAttack, 'defendingEfficiency' => CombatFixedPoint::format($this->defendingEfficiency),
            'capturable' => $this->capturable, 'base' => $this->base, 'effects' => $this->effects];
    }
}
