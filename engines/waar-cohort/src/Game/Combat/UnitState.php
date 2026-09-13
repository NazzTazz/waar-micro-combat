<?php

namespace App\Game\Combat;

enum UnitState: string
{
    case Valid = 'valid';
    case Wounded = 'wounded';
    case Dead = 'dead';
}
