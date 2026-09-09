<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\CombatRuleset;
use Waar\MicroCombat\CombatTieBreakPolicy;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\UnitType;

final readonly class ExperimentVariant
{
    public function __construct(
        public string $id,
        public string $label,
        public UnitCatalog $catalog,
        public CombatRuleset $ruleset,
        /** @var list<array{acting: string, target: string, factor: string|int}> */
        public array $counters,
        public ?CombatTieBreakPolicy $declaredTieBreakPolicy = null,
    ) {
        if ('' === trim($id) || '' === trim($label)) {
            throw new \InvalidArgumentException('Variant id and label are required.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $id = self::text($values, 'id');
        $label = self::text($values, 'label');
        $version = self::text($values, 'version');
        $maxRounds = $values['maxRounds'] ?? null;
        if (!is_int($maxRounds)) {
            throw new \InvalidArgumentException('Variant maxRounds must be an integer.');
        }
        $spread = $values['randomSpread'] ?? null;
        if (!is_string($spread) && !is_int($spread)) {
            throw new \InvalidArgumentException('Variant randomSpread must be a decimal string or integer.');
        }
        $units = $values['units'] ?? null;
        if (!is_array($units)) {
            throw new \InvalidArgumentException('Variant units must be an object.');
        }
        $declaredTieBreakPolicy = null;
        if (array_key_exists('tieBreakPolicy', $values)) {
            $policy = $values['tieBreakPolicy'];
            if (!is_string($policy) || null === ($declaredTieBreakPolicy = CombatTieBreakPolicy::tryFrom($policy))) {
                throw new \InvalidArgumentException('Variant tieBreakPolicy must be "draw" or "defender".');
            }
        }
        $ruleset = CombatRuleset::neutral(
            $id,
            $version,
            $maxRounds,
            $spread,
            $declaredTieBreakPolicy ?? CombatTieBreakPolicy::Draw,
        );
        $counters = $values['counters'] ?? [];
        if (!is_array($counters) || !array_is_list($counters)) {
            throw new \InvalidArgumentException('Variant counters must be a list.');
        }
        $normalizedCounters = [];
        foreach ($counters as $counter) {
            if (!is_array($counter)) {
                throw new \InvalidArgumentException('Each counter must be an object.');
            }
            $acting = UnitType::from(self::text($counter, 'acting'));
            $target = UnitType::from(self::text($counter, 'target'));
            $factor = $counter['factor'] ?? null;
            if (!is_string($factor) && !is_int($factor)) {
                throw new \InvalidArgumentException('Counter factor must be a decimal string or integer.');
            }
            $ruleset = $ruleset->withDamageFactor($acting, $target, $factor);
            $normalizedCounters[] = ['acting' => $acting->value, 'target' => $target->value, 'factor' => $factor];
        }

        return new self($id, $label, UnitCatalog::fromArray($units), $ruleset, $normalizedCounters, $declaredTieBreakPolicy);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = [
            'id' => $this->id,
            'label' => $this->label,
            'version' => $this->ruleset->version,
            'maxRounds' => $this->ruleset->maxRounds,
            'randomSpread' => FixedPoint::format($this->ruleset->randomSpreadMicro),
            'units' => $this->catalog->toArray(),
            'counters' => $this->counters,
        ];
        if (null !== $this->declaredTieBreakPolicy) {
            $values['tieBreakPolicy'] = $this->declaredTieBreakPolicy->value;
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private static function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }
}
