<?php

namespace App\Game\Combat;

use App\Game\Combat\Rules\CombatRuleset;

interface CombatResolverInterface
{
    public function resolve(CombatArmy $attacker, CombatArmy $defender, CombatRuleset $ruleset, CombatSnapshot $snapshot): CombatResult;
}
