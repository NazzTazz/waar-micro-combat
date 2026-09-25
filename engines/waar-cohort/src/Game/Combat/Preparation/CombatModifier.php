<?php

namespace App\Game\Combat\Preparation;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class CombatModifier
{
    public const DECIMAL_PARAMETERS = ['attack', 'structure', 'baseAccuracy', 'accuracySpread', 'defendingEfficiency'];
    public const INTEGER_PARAMETERS = ['cost', 'strikesPerAttack'];

    public function __construct(
        public string $source,
        public string $id,
        public string $label,
        public UnitType $unitType,
        public string $parameter,
        public string $operation,
        public string|bool $value,
    ) {
        if ('' === trim($source) || '' === trim($id) || '' === trim($label)) {
            throw new \InvalidArgumentException('Modifier source, id and label are required.');
        }
        if ('weather' === $source && (!in_array($parameter, ['attack', 'baseAccuracy'], true) || 'multiply' !== $operation)) {
            throw new \InvalidArgumentException('Weather may only multiply attack or base accuracy.');
        }
        if (in_array($parameter, [...self::DECIMAL_PARAMETERS, ...self::INTEGER_PARAMETERS], true)) {
            if ('multiply' !== $operation || !is_string($value)) {
                throw new \InvalidArgumentException('Numeric modifiers use a decimal multiply operation.');
            }
            $units = CombatFixedPoint::units($value);
            if ($units < 0 || $units > 10 * CombatFixedPoint::SCALE) {
                throw new \InvalidArgumentException('Modifier coefficient must be between 0 and 10.');
            }
        } elseif ('capturable' === $parameter) {
            if ('set' !== $operation || !is_bool($value)) {
                throw new \InvalidArgumentException('Capturable modifiers use a boolean set operation.');
            }
        } else {
            throw new \InvalidArgumentException("Unknown modifier parameter: {$parameter}");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if ($unknown = array_diff(array_keys($data), ['source', 'id', 'label', 'unitType', 'parameter', 'operation', 'value'])) {
            throw new \InvalidArgumentException('Unknown modifier field: '.reset($unknown));
        }
        return new self(
            self::text($data, 'source'),
            self::text($data, 'id'),
            self::text($data, 'label'),
            UnitType::from(self::text($data, 'unitType')),
            self::text($data, 'parameter'),
            self::text($data, 'operation'),
            is_bool($data['value'] ?? null) ? $data['value'] : self::text($data, 'value')
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['source' => $this->source, 'id' => $this->id, 'label' => $this->label, 'unitType' => $this->unitType->value,
            'parameter' => $this->parameter, 'operation' => $this->operation, 'value' => $this->value];
    }

    /** @param array<string,mixed> $data */
    private static function text(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || '' === trim($data[$key])) {
            throw new \InvalidArgumentException("Modifier {$key} must be a non-empty string.");
        }
        return $data[$key];
    }
}
