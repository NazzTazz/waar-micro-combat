<?php

namespace App\Game\Army;

enum UnitType: string
{
    case Soldier = 'soldier';
    case Spearman = 'spearman';
    case Archer = 'archer';
    case Knight = 'knight';

    public function label(): string
    {
        return match ($this) {
            self::Soldier => 'Soldat',
            self::Spearman => 'Lancier',
            self::Archer => 'Archer',
            self::Knight => 'Chevalier',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Soldier => 'Soldats',
            self::Spearman => 'Lanciers',
            self::Archer => 'Archers',
            self::Knight => 'Chevaliers',
        };
    }
}
