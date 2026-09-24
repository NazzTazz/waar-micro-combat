<?php

namespace Waar\MicroCombat;

final class Lcg31
{
    private const MODULUS = 2_147_483_648;
    private const MULTIPLIER = 1_103_515_245;
    private const INCREMENT = 12_345;

    private int $state;

    public function __construct(int $seed)
    {
        if ($seed < 0 || $seed >= self::MODULUS) {
            throw new \InvalidArgumentException('Seed must be between 0 and 2^31 - 1.');
        }
        $this->state = $seed;
    }

    public function nextState(): int
    {
        $this->state = (self::MULTIPLIER * $this->state + self::INCREMENT) % self::MODULUS;

        return $this->state;
    }

    public function nextFactor(int $spreadMicro): int
    {
        if ($spreadMicro < 0 || $spreadMicro > 500_000) {
            throw new \InvalidArgumentException('Random spread must be between 0 and 0.5.');
        }
        $state = $this->nextState();
        $delta = 2 * $state - self::MODULUS;
        $magnitude = FixedPoint::checkedMultiply($spreadMicro, abs($delta));
        $offset = FixedPoint::roundDivNearestSigned($delta < 0 ? -$magnitude : $magnitude, self::MODULUS);

        return FixedPoint::SCALE + $offset;
    }

    /** Uniform buckets using high bits, with rejection instead of modulo bias. */
    public function nextIndex(int $count): int
    {
        if ($count < 1 || $count > self::MODULUS) {
            throw new \InvalidArgumentException('Invalid target count.');
        }
        $bucket = intdiv(self::MODULUS, $count);
        $limit = $bucket * $count;
        do {
            $draw = $this->nextState();
        } while ($draw >= $limit);
        return intdiv($draw, $bucket);
    }
}
