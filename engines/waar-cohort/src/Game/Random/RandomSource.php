<?php

namespace App\Game\Random;

interface RandomSource
{
    public function seed(): int;
    public function nextFloat(): float;
    public function binomial(int $trials, float $probability): int;
}
