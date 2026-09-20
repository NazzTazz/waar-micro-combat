<?php

namespace Waar\MicroCombat\Workshop;

interface CohortRuntime
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function resolve(array $request): array;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function batch(array $request): array;

    /** @return array{kind:string,transport:string,modelVersion:string} */
    public function provenance(): array;
}
