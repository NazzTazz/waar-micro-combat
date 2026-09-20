<?php

namespace App\Game\Random;

interface SeedGenerator
{
    public function nextSeed(): int;
}
