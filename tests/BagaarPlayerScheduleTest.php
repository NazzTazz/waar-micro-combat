<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\PlayerSchedule;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarPlayerScheduleTest extends TestCase
{
    public function testOfficeWindowStartsAtNineAndEndsBeforeSeventeenEachDay(): void
    {
        self::assertFalse(PlayerSchedule::isActive('office', 9));
        self::assertTrue(PlayerSchedule::isActive('office', 10));
        self::assertTrue(PlayerSchedule::isActive('office', 17));
        self::assertFalse(PlayerSchedule::isActive('office', 18));
        self::assertTrue(PlayerSchedule::isActive('office', 34));
        self::assertTrue(PlayerSchedule::isActive('all-day', 18));
    }
}
