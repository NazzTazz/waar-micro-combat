<?php

namespace Waar\MicroCombat;

final readonly class PreparedBattle
{
    public function __construct(
        public CombatRuleset $ruleset,
        public PreparedArmy $attacker,
        public PreparedArmy $defender,
        public int $seed,
    ) {
        if ($seed < 0 || $seed > 2_147_483_647) {
            throw new \InvalidArgumentException('Seed must be between 0 and 2^31 - 1.');
        }
    }
}
