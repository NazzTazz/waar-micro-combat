<?php

namespace App\Game\Combat\Rules;

use App\Game\Army\UnitType;

final class WaarRuleset
{
    public static function create(string $version = 'waar-cohort-v2-default'): CombatRuleset
    {
        return new CombatRuleset($version, [
            new UnitDefinition(UnitType::Soldier, 6, 6, 10, '0.20', '0.01', 1, 1, true),
            new UnitDefinition(UnitType::Spearman, 20, 50, 70, '0.15', '0.02', 1, '1.25', false),
            new UnitDefinition(UnitType::Archer, 60, 50, 70, '0.15', '0.02', 1, '0.75', true),
            new UnitDefinition(UnitType::Knight, 350, 250, 550, '0.15', '0.01', 1, 1, false),
        ], TargetPreferenceMatrix::neutral(), EngagementMatrix::neutral());
    }
}
