<?php

namespace App\Game\Combat;

enum VictoryReason:string
{
    case Elimination='elimination';
    case Surrender='surrender';
    case RoundLimit='round_limit';
    case InitialEmpty='initial_empty';
}
