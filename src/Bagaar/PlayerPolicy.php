<?php

namespace Waar\MicroCombat\Bagaar;

/** Future script engines implement this boundary without receiving the full era state. */
interface PlayerPolicy
{
    /** @param array<string,mixed> $observation @return array<string,mixed>|null */
    public function next(array $observation): ?array;
}
