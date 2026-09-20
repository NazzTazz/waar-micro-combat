<?php

namespace App\Game\Combat\Rules;

use App\Game\Army\UnitType;

final readonly class TargetPreferenceMatrix
{
    /** @var array<string, array<string, float>> */
    private array $weights;

    /** @param array<string, array<string, float|int>> $weights */
    public function __construct(array $weights)
    {
        $normalized = [];
        foreach (UnitType::cases() as $attacker) {
            foreach (UnitType::cases() as $target) {
                $weight = (float) ($weights[$attacker->value][$target->value] ?? 0.0);
                if (!is_finite($weight) || $weight <= 0) {
                    throw new \InvalidArgumentException('Every target preference weight must be strictly positive.');
                }
                $normalized[$attacker->value][$target->value] = $weight;
            }
        }
        $this->weights = $normalized;
    }

    public function weight(UnitType $attacker, UnitType $target): float
    {
        return $this->weights[$attacker->value][$target->value];
    }

    /** @return array<string, array{weights: array<string, float>}> */
    public function toArray(): array
    {
        $data = [];
        foreach (UnitType::cases() as $attacker) {
            $data[$attacker->value] = ['weights' => $this->weights[$attacker->value]];
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $known=array_map(static fn(UnitType $type)=>$type->value,UnitType::cases());if(array_diff(array_keys($data),$known))throw new \InvalidArgumentException('Unknown targeting row.');
        $weights = [];
        foreach (UnitType::cases() as $attacker) {
            $row=(array)($data[$attacker->value]??[]);if(array_diff(array_keys($row),['weights'])||!is_array($row['weights']??null)||array_diff(array_keys($row['weights']),$known))throw new \InvalidArgumentException('Invalid targeting row.');$weights[$attacker->value]=$row['weights'];
        }
        return new self($weights);
    }

    public static function neutral(): self
    {
        $weights = [];
        foreach (UnitType::cases() as $attacker) {
            foreach (UnitType::cases() as $target) {
                $weights[$attacker->value][$target->value] = 1.0;
            }
        }
        return new self($weights);
    }
}
