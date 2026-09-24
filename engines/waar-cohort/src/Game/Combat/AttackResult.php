<?php

namespace App\Game\Combat;

final readonly class AttackResult
{
    /** @param array<string,array<string,array<string,mixed>>> $matrix */
    public function __construct(public CombatArmy $targetArmy, public int $attempts, public int $hits, public int $deaths, public array $matrix)
    {
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['attempts' => $this->attempts, 'hits' => $this->hits, 'deaths' => $this->deaths, 'matrix' => $this->matrix];
    }
}
