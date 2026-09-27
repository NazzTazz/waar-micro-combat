<?php

namespace Waar\MicroCombat\Bagaar;

/** Deterministic newcomers who replace abandoned players in the field. */
final class PlayerEntrants
{
    private const NAMES = ['Adèle', 'Basile', 'Céleste', 'Diego', 'Elina', 'Florian',
        'Gaspard', 'Héloïse', 'Inès', 'Jonas', 'Lina', 'Maël', 'Noémie', 'Orion',
        'Rita', 'Sacha', 'Théo', 'Yasmine'];

    public static function create(int $serial, int $tick): array
    {
        if ($serial < 1 || $tick < 1) {
            throw new \InvalidArgumentException('Entrée de joueur invalide.');
        }
        $policy = BuiltinPolicy::NAMES[($serial - 1) % count(BuiltinPolicy::NAMES)];
        $id = 'entrant-'.$serial;
        $player = AccountRules::initial($id, $policy);
        $name = self::NAMES[($serial - 1) % count(self::NAMES)]
            .($serial > count(self::NAMES) ? ' '.(int) ceil($serial / count(self::NAMES)) : '');
        $player['originName'] = $name;
        $player['resetCount'] = 0;
        $player['name'] = $name;
        $player['joinedTick'] = $tick;
        $activity = ['all-day', 'office', 'evening', 'early'][$serial % 4];
        if ($policy === 'casual') {
            $activity = ['casual-morning', 'casual-noon', 'casual-evening', 'casual-night'][$serial % 4];
        }
        $player['activity'] = $activity;
        $player['aggressionPercent'] = [80, 95, 105, 120][$serial % 4];
        if ($policy === 'grenouille') {
            $player['soldierParadigm'] = $serial % 2 === 0;
        }
        return $player;
    }
}
