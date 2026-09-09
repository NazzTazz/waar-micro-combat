<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\PreparedUnit;
use Waar\MicroCombat\UnitType;

final readonly class UnitProfile
{
    public function __construct(
        public UnitType $type,
        public int $attackMicro,
        public int $structureMicro,
        public int $defendingEfficiencyMicro,
        public int $cost,
    ) {
        if ($attackMicro < 0 || $structureMicro <= 0 || $cost < 0) {
            throw new \InvalidArgumentException('Invalid experimental unit profile.');
        }
        if ($defendingEfficiencyMicro < 0 || $defendingEfficiencyMicro > 10 * FixedPoint::SCALE) {
            throw new \InvalidArgumentException('Defending efficiency must be between 0 and 10.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(UnitType $type, array $values): self
    {
        return new self(
            $type,
            FixedPoint::parse(self::decimal($values, 'attack')),
            FixedPoint::parse(self::decimal($values, 'structure')),
            FixedPoint::parse(self::decimal($values, 'defendingEfficiency')),
            self::integer($values, 'cost', 0),
        );
    }

    public function prepare(int $count): PreparedUnit
    {
        return new PreparedUnit(
            $this->type,
            $count,
            $this->attackMicro,
            $this->structureMicro,
            $this->defendingEfficiencyMicro,
            $this->cost,
        );
    }

    /** @return array{attack: string, structure: string, defendingEfficiency: string, cost: int} */
    public function toArray(): array
    {
        return [
            'attack' => FixedPoint::format($this->attackMicro),
            'structure' => FixedPoint::format($this->structureMicro),
            'defendingEfficiency' => FixedPoint::format($this->defendingEfficiencyMicro),
            'cost' => $this->cost,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function decimal(array $values, string $key): string|int
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Unit field "%s" must be a decimal string or integer.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key, int $minimum): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value < $minimum) {
            throw new \InvalidArgumentException(sprintf('Unit field "%s" must be an integer >= %d.', $key, $minimum));
        }

        return $value;
    }
}
