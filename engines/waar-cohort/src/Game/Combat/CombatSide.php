<?php

namespace App\Game\Combat;

enum CombatSide: string
{
    case Attacker = 'attacker';
    case Defender = 'defender';
}
