<?php

namespace Waar\MicroCombat;

enum CombatTieBreakPolicy: string
{
    case Draw = 'draw';
    case Defender = 'defender';
}
