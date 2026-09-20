<?php

namespace App\Game\Combat\Rules;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class UnitDefinition
{
    public float $attack;
    public float $structure;
    public float $baseAccuracy;
    public float $accuracySpread;
    public float $defendingEfficiency;

    public function __construct(
        public UnitType $type, float|int|string $attack, float|int|string $structure, public int $cost,
        float|int|string $baseAccuracy, float|int|string $accuracySpread = 0,
        public int $strikesPerAttack = 1, float|int|string $defendingEfficiency = 1,
        public bool $capturable = false,
    ) {
        $this->attack = CombatFixedPoint::canonicalize($attack);
        $this->structure = CombatFixedPoint::canonicalize($structure);
        $this->baseAccuracy = CombatFixedPoint::canonicalize($baseAccuracy);
        $this->accuracySpread = CombatFixedPoint::canonicalize($accuracySpread);
        $this->defendingEfficiency = CombatFixedPoint::canonicalize($defendingEfficiency);
        if ($this->attack < 0 || $this->attack > 1000 || $this->structure <= 0 || $this->structure > 1000
            || $cost < 1 || $cost > 400400 || $this->baseAccuracy < 0 || $this->baseAccuracy > 1
            || $this->accuracySpread < 0 || $this->accuracySpread > 1 || $strikesPerAttack < 1 || $strikesPerAttack > 32
            || $this->defendingEfficiency < 0 || $this->defendingEfficiency > 10) {
            throw new \InvalidArgumentException('Invalid unit definition.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['type'=>$this->type->value, 'attack'=>CombatFixedPoint::format($this->attack),
            'structure'=>CombatFixedPoint::format($this->structure), 'cost'=>$this->cost,
            'baseAccuracy'=>CombatFixedPoint::format($this->baseAccuracy), 'accuracySpread'=>CombatFixedPoint::format($this->accuracySpread),
            'strikesPerAttack'=>$this->strikesPerAttack, 'defendingEfficiency'=>CombatFixedPoint::format($this->defendingEfficiency),
            'capturable'=>$this->capturable];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if($unknown=array_diff(array_keys($data),['type','attack','structure','cost','baseAccuracy','accuracySpread','strikesPerAttack','defendingEfficiency','capturable']))throw new \InvalidArgumentException('Unknown unit field: '.reset($unknown));
        foreach (['attack','structure','baseAccuracy'] as $key) if (!is_int($data[$key] ?? null) && !is_float($data[$key] ?? null) && !is_string($data[$key] ?? null)) {
            throw new \InvalidArgumentException("Unit {$key} must be a decimal.");
        }
        return new self(UnitType::from((string) ($data['type'] ?? '')), $data['attack'], $data['structure'],
            self::integer($data, 'cost'), $data['baseAccuracy'], self::decimal($data, 'accuracySpread', '0'),
            self::integer($data, 'strikesPerAttack', 1), self::decimal($data, 'defendingEfficiency', '1'),
            self::boolean($data, 'capturable', false));
    }

    /** @param array<string,mixed> $data */
    private static function decimal(array $data, string $key, string $default): int|float|string
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value) && !is_float($value) && !is_string($value)) throw new \InvalidArgumentException("Unit {$key} must be a decimal.");
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function integer(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value)) throw new \InvalidArgumentException("Unit {$key} must be an integer.");
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function boolean(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;
        if (!is_bool($value)) throw new \InvalidArgumentException("Unit {$key} must be a boolean.");
        return $value;
    }
}
