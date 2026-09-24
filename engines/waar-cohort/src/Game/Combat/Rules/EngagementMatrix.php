<?php

namespace App\Game\Combat\Rules;

use App\Game\Army\UnitType;

final readonly class EngagementMatrix
{
    /** @var array<string, array<string, EngagementRule>> */
    private array $rules;

    /** @param array<string, array<string, EngagementRule>> $rules */
    public function __construct(array $rules)
    {
        foreach (UnitType::cases() as $attacker) {
            foreach (UnitType::cases() as $target) {
                if (!isset($rules[$attacker->value][$target->value])) {
                    throw new \InvalidArgumentException("Missing engagement rule {$attacker->value} -> {$target->value}.");
                }
            }
        }
        $this->rules = $rules;
    }

    public function get(UnitType $attacker, UnitType $target): EngagementRule
    {
        return $this->rules[$attacker->value][$target->value];
    }

    /** @return array<string, array<string, array{attackFactor: string, isProvisional: bool}>> */
    public function toArray(): array
    {
        $data = [];
        foreach (UnitType::cases() as $attacker) {
            foreach (UnitType::cases() as $target) {
                $data[$attacker->value][$target->value] = $this->get($attacker, $target)->toArray();
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $known = array_map(static fn (UnitType $type) => $type->value, UnitType::cases());
        if (array_diff(array_keys($data), $known)) {
            throw new \InvalidArgumentException('Unknown engagement row.');
        }
        $rules = [];
        foreach (UnitType::cases() as $attacker) {
            if (array_diff(array_keys((array)($data[$attacker->value] ?? [])), $known)) {
                throw new \InvalidArgumentException('Unknown engagement target.');
            }
            foreach (UnitType::cases() as $target) {
                $rules[$attacker->value][$target->value] = EngagementRule::fromArray($data[$attacker->value][$target->value] ?? []);
            }
        }
        return new self($rules);
    }

    public static function neutral(): self
    {
        $rules = [];
        foreach (UnitType::cases() as $attacker) {
            foreach (UnitType::cases() as $target) {
                $rules[$attacker->value][$target->value] = new EngagementRule(1);
            }
        }
        return new self($rules);
    }
}
