<?php

namespace App\Game\Random;

/** OS-backed adapter; game resolution itself only consumes RandomSource. */
final class SystemSeedGenerator implements SeedGenerator
{
    public function nextSeed(): int
    {
        return unpack('N', random_bytes(4))[1] & 0x7fffffff;
    }
}
