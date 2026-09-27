<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\HostRules;
use Waar\MicroCombat\Bagaar\VillageRules;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarHostRulesTest extends TestCase
{
    public function testBuildingsKeepWaarPricesButArmyUsesPresetPrices(): void
    {
        self::assertSame(['level' => 9, 'gold' => 1944, 'glory' => 20], HostRules::mineUpgrade(8));
        self::assertSame(35, HostRules::mineProduction(2));
        self::assertSame(['level' => 2, 'gold' => 2500, 'glory' => 5], HostRules::hospitalUpgrade(1));
        self::assertSame(660, HostRules::armyValue(['soldier' => 2, 'spearman' => 1, 'archer' => 0, 'knight' => 1], ['soldier' => 100, 'spearman' => 160, 'archer' => 200, 'knight' => 300]));
    }

    public function testCompositionChangesHourlyQuotas(): void
    {
        self::assertSame(['attacks' => 9, 'defenses' => 3], HostRules::quotas(HostRules::emptyArmy()));
        self::assertSame(['attacks' => 24, 'defenses' => 3], HostRules::quotas(['soldier' => 0, 'spearman' => 0, 'archer' => 0, 'knight' => 10]));
        self::assertSame(['attacks' => 9, 'defenses' => 18], HostRules::quotas(['soldier' => 0, 'spearman' => 10, 'archer' => 0, 'knight' => 0]));
    }

    public function testSurrenderRequiresNineConsecutiveDefensiveLossesAndResetsState(): void
    {
        $player = ['autoSurrender' => true, 'defenseLossStreak' => 0, 'glory' => 45, 'prisoners' => 7, 'morale' => 65, 'surrenders' => 0];
        for ($i = 0; $i < 8; $i++) {
            $player = HostRules::reportDefenseResult($player, true);
        }
        self::assertSame(45, $player['glory']);
        $player = HostRules::reportDefenseResult($player, false);
        self::assertSame(0, $player['defenseLossStreak']);
        for ($i = 0; $i < 9; $i++) {
            $player = HostRules::reportDefenseResult($player, true);
        }
        self::assertSame(15, $player['glory']);
        self::assertSame(0, $player['prisoners']);
        self::assertSame(100, $player['morale']);
        self::assertSame(0, $player['defenseLossStreak']);
        self::assertSame(1, $player['surrenders']);
    }

    public function testVillageAppearsOnlyAfterLeaderReachesSixtyGlory(): void
    {
        self::assertSame([], VillageRules::availableTiers(0, 59));
        self::assertSame([20], VillageRules::availableTiers(0, 60));
        self::assertSame([], VillageRules::availableTiers(61, 60));
    }

    public function testVillageCapsUseSelectedPresetPrices(): void
    {
        $players = [['glory' => 20, 'army' => ['soldier' => 1000, 'spearman' => 0, 'archer' => 0, 'knight' => 0]]];
        $cheap = VillageRules::caps(20, $players, ['soldier' => 10, 'spearman' => 70, 'archer' => 70, 'knight' => 550]);
        $dear = VillageRules::caps(20, $players, ['soldier' => 100, 'spearman' => 160, 'archer' => 200, 'knight' => 300]);
        self::assertNotSame($cheap['army'], $dear['army']);
        self::assertSame(0, $dear['army']['archer']);
    }

    public function testEspionageReportHidesComposition(): void
    {
        $report = HostRules::espionageReport(['gold' => 123, 'glory' => 12, 'army' => ['soldier' => 1, 'spearman' => 2, 'archer' => 3, 'knight' => 4], 'morale' => 99]);
        self::assertSame(['gold' => 123, 'armyTotal' => 10, 'glory' => 12, 'morale' => 'low'], $report);
        self::assertSame(5, HostRules::espionageCost(12));
    }
}
