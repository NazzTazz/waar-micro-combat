<?php

namespace Waar\MicroCombat;

final readonly class CombatRuleset
{
    /** @var array<string, array<string, int>> */
    private array $damageFactors;

    /** @param array<string, array<string, int>> $damageFactors */
    public function __construct(
        public string $id,
        public string $version,
        public int $maxRounds,
        public int $randomSpreadMicro,
        array $damageFactors,
        public CombatTieBreakPolicy $tieBreakPolicy = CombatTieBreakPolicy::Draw,
    ) {
        if ('' === trim($id) || '' === trim($version) || $maxRounds < 1 || $maxRounds > 30 || $randomSpreadMicro < 0 || $randomSpreadMicro > 500_000) {
            throw new \InvalidArgumentException('Invalid micro combat ruleset metadata.');
        }
        $normalized = [];
        foreach (UnitType::cases() as $acting) {
            foreach (UnitType::cases() as $target) {
                $factor = $damageFactors[$acting->value][$target->value] ?? null;
                if (!is_int($factor) || $factor < 0 || $factor > 10 * FixedPoint::SCALE) {
                    throw new \InvalidArgumentException('Every directed damage factor must be an integer between 0 and 10 micro-scaled.');
                }
                $normalized[$acting->value][$target->value] = $factor;
            }
        }
        $this->damageFactors = $normalized;
    }

    public static function neutral(
        string $id,
        string $version,
        int $maxRounds,
        string|int $randomSpread = 0,
        CombatTieBreakPolicy $tieBreakPolicy = CombatTieBreakPolicy::Draw,
    ): self
    {
        $factors = [];
        foreach (UnitType::cases() as $acting) {
            foreach (UnitType::cases() as $target) {
                $factors[$acting->value][$target->value] = FixedPoint::SCALE;
            }
        }

        return new self($id, $version, $maxRounds, FixedPoint::parse($randomSpread), $factors, $tieBreakPolicy);
    }

    public function damageFactor(UnitType $acting, UnitType $target): int
    {
        return $this->damageFactors[$acting->value][$target->value];
    }

    public function withDamageFactor(UnitType $acting, UnitType $target, string|int $factor): self
    {
        $factors = $this->damageFactors;
        $factors[$acting->value][$target->value] = FixedPoint::parse($factor);

        return new self($this->id, $this->version, $this->maxRounds, $this->randomSpreadMicro, $factors, $this->tieBreakPolicy);
    }
}
