<?php

namespace Waar\MicroCombat;

enum CombatSide: string
{
    case Attacker = 'attacker';
    case Defender = 'defender';

    public function opponent(): self
    {
        return self::Attacker === $this ? self::Defender : self::Attacker;
    }
}
