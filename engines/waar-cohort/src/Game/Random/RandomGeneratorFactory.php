<?php

namespace App\Game\Random;

final class RandomGeneratorFactory
{
    public function create(StochasticEngineVersion $version, int $seed): RandomSource
    {
        return match ($version) {
            StochasticEngineVersion::Lcg31NormalApproximationV1 => new SeededRandomSource($seed),
            StochasticEngineVersion::AddressedBinomialV1 => throw new \InvalidArgumentException('Addressed randomness requires an army and event context.'),
        };
    }
}
