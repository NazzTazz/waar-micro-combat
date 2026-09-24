<?php

namespace App\Infrastructure\Combat;

/** @deprecated The v2 boundary already is the versioned JSON request. */
final class RustCombatPayloadMapper
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function request(array $request): array
    {
        return $request;
    }
}
