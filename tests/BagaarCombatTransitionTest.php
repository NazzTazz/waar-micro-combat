<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\CombatTransition;
use Waar\MicroCombat\Bagaar\HostRules;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarCombatTransitionTest extends TestCase
{
    public function testAttackerVictoryCanTriggerImmediateDefenderSurrender(): void
    {
        $attacker = self::player();
        $defender = self::player();
        $defender['defenseLossStreak'] = 8;
        $report = self::report('attacker', [8, 2, 0, 0], [8, 1, 1, 0]);
        $after = CombatTransition::apply($attacker, $defender, $report, 200);
        self::assertSame(8, $after['attacker']['army']['soldier']);
        self::assertSame(2, $after['attacker']['hospital']['soldier']);
        self::assertSame(2200, $after['attacker']['gold']);
        self::assertSame(1800, $after['defender']['gold']);
        self::assertSame(11, $after['attacker']['glory']);
        self::assertSame(0, $after['defender']['glory']);
        self::assertSame(1, $after['defender']['surrenders']);
        self::assertSame(8, $after['attacker']['attacks']);
        self::assertSame(2, $after['defender']['defenses']);
        self::assertTrue($after['event']['surrender']);
    }

    public function testDrawConsumesQuotasAndBreaksSurrenderStreakWithoutTransfers(): void
    {
        $attacker = self::player();
        $defender = self::player();
        $defender['defenseLossStreak'] = 8;
        $after = CombatTransition::apply($attacker, $defender, self::report(null, [9, 1, 0, 0], [9, 1, 0, 0]), 0);
        self::assertSame(2000, $after['attacker']['gold']);
        self::assertSame(2000, $after['defender']['gold']);
        self::assertSame(10, $after['attacker']['glory']);
        self::assertSame(10, $after['defender']['glory']);
        self::assertSame(0, $after['defender']['defenseLossStreak']);
        self::assertSame(9, $after['attacker']['army']['soldier']);
        self::assertSame(1, $after['defender']['hospital']['soldier']);
        self::assertSame(['winner' => null, 'loot' => 0, 'prisoners' => 0, 'surrender' => false], $after['event']);
    }

    private static function player(): array
    {
        return ['gold' => 2000, 'glory' => 10, 'mineLevel' => 0, 'hospitalLevel' => 1,
            'army' => ['soldier' => 10, 'spearman' => 0, 'archer' => 0, 'knight' => 0],
            'hospital' => HostRules::emptyArmy(), 'prisoners' => 0, 'morale' => 100,
            'attacks' => 9, 'defenses' => 3, 'autoSurrender' => true, 'defenseLossStreak' => 0, 'surrenders' => 0];
    }

    private static function report(?string $winner, array $attackerSoldier, array $defenderSoldier): array
    {
        $sides = [];
        foreach (['attacker' => $attackerSoldier, 'defender' => $defenderSoldier] as $side => $soldier) {
            $types = [];
            foreach (HostRules::UNIT_TYPES as $type) {
                [$healthy, $wounded, $dead, $prisoners] = $type === 'soldier' ? $soldier : [0, 0, 0, 0];
                $types[$type] = ['initial' => $type === 'soldier' ? 10 : 0,
                    'projected' => compact('healthy', 'wounded', 'dead', 'prisoners')];
            }
            $sides[$side] = ['types' => $types];
        }
        return ['result' => ['schemaVersion' => 'waar-combat-result/2', 'winner' => $winner],
            'consequences' => ['schemaVersion' => 'waar-combat-consequences/1', ...$sides]];
    }
}
