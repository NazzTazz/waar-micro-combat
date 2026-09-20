<?php

namespace App\Game\Random;

final class SeededRandomSource implements RandomSource
{
    private int $state;

    public function __construct(private readonly int $initialSeed)
    {
        $this->state = $initialSeed & 0x7fffffff;
    }

    public function seed(): int { return $this->initialSeed; }

    public function nextFloat(): float
    {
        $this->state = (int) ((1103515245 * $this->state + 12345) % 2147483648);
        return $this->state / 2147483648;
    }

    public function binomial(int $trials, float $probability): int
    {
        if ($trials <= 0 || $probability <= 0) { return 0; }
        if ($probability >= 1) { return $trials; }
        if ($trials <= 64) {
            $successes = 0;
            for ($i = 0; $i < $trials; ++$i) {
                $successes += $this->nextFloat() < $probability ? 1 : 0;
            }
            return $successes;
        }
        $u1 = max($this->nextFloat(), PHP_FLOAT_MIN);
        $u2 = $this->nextFloat();
        $normal = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        $sample = (int) round($trials * $probability + $normal * sqrt($trials * $probability * (1 - $probability)));
        return max(0, min($trials, $sample));
    }
}
