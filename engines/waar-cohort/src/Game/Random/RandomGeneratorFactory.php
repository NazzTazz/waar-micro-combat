<?php

namespace App\Game\Random;

final class RandomGeneratorFactory
{
    public function create(StochasticEngineVersion $version, int $seed): RandomSource
    {
        return match ($version) {
            StochasticEngineVersion::Lcg31NormalApproximationV1 => new SeededRandomSource($seed),
        };
    }
}
