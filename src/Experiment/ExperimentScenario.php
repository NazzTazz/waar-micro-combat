<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\UnitType;

final readonly class ExperimentScenario
{
    /** @param array<string, int> $attacker @param array<string, int> $defender */
    public function __construct(
        public string $id,
        public string $label,
        public CombatSide $focusSide,
        public array $attacker,
        public array $defender,
    ) {
        if ('' === trim($id) || '' === trim($label)) {
            throw new \InvalidArgumentException('Scenario id and label are required.');
        }
        self::validateArmy($attacker);
        self::validateArmy($defender);
        if (0 === array_sum($attacker) || 0 === array_sum($defender)) {
            throw new \InvalidArgumentException('Both scenario armies must contain at least one unit.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $id = $values['id'] ?? null;
        $label = $values['label'] ?? null;
        $focusSide = $values['focusSide'] ?? null;
        $attacker = $values['attacker'] ?? null;
        $defender = $values['defender'] ?? null;
        if (!is_string($id) || '' === trim($id) || !is_string($label) || '' === trim($label) || !is_string($focusSide)) {
            throw new \InvalidArgumentException('Invalid scenario metadata.');
        }
        if (!is_array($attacker) || !is_array($defender)) {
            throw new \InvalidArgumentException('Scenario armies must be objects.');
        }

        return new self($id, $label, CombatSide::from($focusSide), self::normalizeArmy($attacker), self::normalizeArmy($defender));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'focusSide' => $this->focusSide->value,
            'attacker' => $this->attacker,
            'defender' => $this->defender,
        ];
    }

    /** @param array<string, mixed> $army @return array<string, int> */
    private static function normalizeArmy(array $army): array
    {
        $knownTypes = array_column(UnitType::cases(), 'value');
        $unknownTypes = array_diff(array_keys($army), $knownTypes);
        if ([] !== $unknownTypes) {
            throw new \InvalidArgumentException('Unknown unit count: '.implode(', ', $unknownTypes).'.');
        }
        $normalized = [];
        foreach (UnitType::cases() as $type) {
            $count = $army[$type->value] ?? 0;
            if (!is_int($count) || $count < 0) {
                throw new \InvalidArgumentException(sprintf('Count for "%s" must be a non-negative integer.', $type->value));
            }
            $normalized[$type->value] = $count;
        }

        return $normalized;
    }

    /** @param array<string, int> $army */
    private static function validateArmy(array $army): void
    {
        if (array_keys($army) !== array_column(UnitType::cases(), 'value')) {
            throw new \InvalidArgumentException('Scenario army must contain the four ordered unit types.');
        }
        foreach ($army as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Scenario counts must be non-negative.');
            }
        }
    }
}
