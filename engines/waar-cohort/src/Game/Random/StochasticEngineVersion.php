<?php

namespace App\Game\Random;

enum StochasticEngineVersion: string
{
    case Lcg31NormalApproximationV1 = 'lcg31-binomial-normal-v1';
    case AddressedBinomialV1 = 'sha256-binomial-tree/1';
}
