<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\PreparedArmy;
use Waar\MicroCombat\UnitType;

final readonly class UnitCatalog
{
    /** @var array<string, UnitProfile> */
    private array $profiles;

    /** @param iterable<UnitProfile> $profiles */
    public function __construct(iterable $profiles)
    {
        $indexed = [];
        foreach ($profiles as $profile) {
            $indexed[$profile->type->value] = $profile;
        }
        foreach (UnitType::cases() as $type) {
            if (!isset($indexed[$type->value])) {
                throw new \InvalidArgumentException(sprintf('Missing unit profile "%s".', $type->value));
            }
        }
        if (count($indexed) !== count(UnitType::cases())) {
            throw new \InvalidArgumentException('Unexpected unit profile.');
        }
        $this->profiles = $indexed;
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $knownTypes = array_column(UnitType::cases(), 'value');
        $unknownTypes = array_diff(array_keys($values), $knownTypes);
        if ([] !== $unknownTypes) {
            throw new \InvalidArgumentException('Unknown unit profile: '.implode(', ', $unknownTypes).'.');
        }
        $profiles = [];
        foreach (UnitType::cases() as $type) {
            $profile = $values[$type->value] ?? null;
            if (!is_array($profile)) {
                throw new \InvalidArgumentException(sprintf('Missing unit profile "%s".', $type->value));
            }
            $profiles[] = UnitProfile::fromArray($type, $profile);
        }

        return new self($profiles);
    }

    /** @param array<string, int> $counts */
    public function prepareArmy(array $counts): PreparedArmy
    {
        $units = [];
        foreach (UnitType::cases() as $type) {
            $count = $counts[$type->value] ?? 0;
            if (!is_int($count) || $count < 0) {
                throw new \InvalidArgumentException(sprintf('Count for "%s" must be a non-negative integer.', $type->value));
            }
            $units[] = $this->profiles[$type->value]->prepare($count);
        }

        return new PreparedArmy($units);
    }

    /** @return array<string, array{attack: string, structure: string, defendingEfficiency: string, cost: int}> */
    public function toArray(): array
    {
        $values = [];
        foreach (UnitType::cases() as $type) {
            $values[$type->value] = $this->profiles[$type->value]->toArray();
        }

        return $values;
    }
}
