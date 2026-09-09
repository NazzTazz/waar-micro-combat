<?php

namespace Waar\MicroCombat;

final readonly class PreparedUnit
{
    public function __construct(
        public UnitType $type,
        public int $count,
        public int $attackMicro,
        public int $structureMicro,
        public int $defendingEfficiencyMicro,
        public int $cost,
    ) {
        if ($count < 0 || $attackMicro < 0 || $structureMicro <= 0 || $cost < 0) {
            throw new \InvalidArgumentException('Invalid prepared unit values.');
        }
        if ($defendingEfficiencyMicro < 0 || $defendingEfficiencyMicro > 10 * FixedPoint::SCALE) {
            throw new \InvalidArgumentException('Defending efficiency must be between 0 and 10.');
        }
        FixedPoint::checkedMultiply($count, $structureMicro);
        FixedPoint::checkedMultiply($count, $attackMicro);
    }

    public static function fromDecimals(UnitType $type, int $count, string|int $attack, string|int $structure, string|int $defendingEfficiency = 1, int $cost = 0): self
    {
        return new self(
            $type,
            $count,
            FixedPoint::parse($attack),
            FixedPoint::parse($structure),
            FixedPoint::parse($defendingEfficiency),
            $cost,
        );
    }
}
