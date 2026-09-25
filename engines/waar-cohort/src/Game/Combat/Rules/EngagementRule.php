<?php

namespace App\Game\Combat\Rules;

use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class EngagementRule
{
    public float $attackFactor;

    public function __construct(
        float|int|string $attackFactor,
        public bool $isProvisional = false,
    ) {
        $this->attackFactor = CombatFixedPoint::canonicalize($attackFactor);
        if ($this->attackFactor < 0 || $this->attackFactor > 100) {
            throw new \InvalidArgumentException('attackFactor must be positive or zero.');
        }
    }

    /** @return array{attackFactor: string, isProvisional: bool} */
    public function toArray(): array
    {
        return ['attackFactor' => CombatFixedPoint::format($this->attackFactor), 'isProvisional' => $this->isProvisional];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if ($unknown = array_diff(array_keys($data), ['attackFactor', 'isProvisional', 'extraBallChance'])) {
            throw new \InvalidArgumentException('Unknown engagement field: '.reset($unknown));
        }
        if (array_key_exists('extraBallChance', $data)) {
            throw new \InvalidArgumentException('extraBallChance was removed in waar-cohort-v2; use strikesPerAttack.');
        }
        return new self($data['attackFactor'] ?? '1', (bool) ($data['isProvisional'] ?? false));
    }
}
