<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\AccountRules;
use Waar\MicroCombat\Bagaar\PlayerEngagement;
use Waar\MicroCombat\Bagaar\PlayerSchedule;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarPlayerEngagementTest extends TestCase
{
    public function testFiveRecentFightsWithFourLossesTriggerPauseThenAbandonment(): void
    {
        $id = 'pause';
        while (hexdec(substr(hash('sha256', $id), 0, 2)) % 4 === 0) {
            $id .= 'x';
        }
        $player = AccountRules::initial($id, 'fermier');
        foreach ([true, true, true, false] as $lost) {
            [$player, $change] = PlayerEngagement::afterCombat($player, 10, $lost);
            self::assertNull($change);
        }
        [$player, $change] = PlayerEngagement::afterCombat($player, 10, true);
        self::assertSame('pause', $change);
        self::assertSame('pause', $player['status']);
        self::assertGreaterThanOrEqual(58, $player['pauseUntil']);
        [$player, $change] = PlayerEngagement::resume($player, $player['pauseUntil'] - 1);
        self::assertNull($change);
        [$player, $change] = PlayerEngagement::resume($player, $player['pauseUntil']);
        self::assertSame('return', $change);
        self::assertSame('active', $player['status']);
        for ($i = 0; $i < 5; $i++) {
            [$player, $change] = PlayerEngagement::afterCombat($player, 110, true);
        }
        self::assertSame('abandon', $change);
        self::assertSame('abandoned', $player['status']);
    }

    public function testLossWindowExpiresAndSomePlayersAbandonImmediately(): void
    {
        $id = 'quit';
        while (hexdec(substr(hash('sha256', $id), 0, 2)) % 4 !== 0) {
            $id .= 'x';
        }
        $player = AccountRules::initial($id, 'casual');
        for ($i = 1; $i <= 4; $i++) {
            [$player] = PlayerEngagement::afterCombat($player, $i, true);
        }
        [$player, $change] = PlayerEngagement::afterCombat($player, 30, true);
        self::assertNull($change);
        self::assertSame('active', $player['status']);
        for ($i = 0; $i < 4; $i++) {
            [$player, $change] = PlayerEngagement::afterCombat($player, 30, true);
        }
        self::assertSame('abandon', $change);
    }

    public function testCasualSchedulesRunOneOrTwoTicksEveryDay(): void
    {
        foreach (['casual-morning' => 1, 'casual-noon' => 2, 'casual-evening' => 1, 'casual-night' => 2] as $window => $dailyTicks) {
            $active = [];
            for ($tick = 1; $tick <= 48; $tick++) {
                if (PlayerSchedule::isActive($window, $tick)) {
                    $active[] = $tick;
                }
            }
            self::assertCount(2 * $dailyTicks, $active, $window);
            for ($i = 0; $i < $dailyTicks; $i++) {
                self::assertSame(24, $active[$i + $dailyTicks] - $active[$i], $window);
            }
        }
    }
}
