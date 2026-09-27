<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\PlayerEntrants;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarPlayerEntrantsTest extends TestCase
{
    public function testEntrantsHaveDistinctFreshAccountsAndDeterministicProfiles(): void
    {
        $first = PlayerEntrants::create(1, 42);
        $second = PlayerEntrants::create(2, 43);
        self::assertSame($first, PlayerEntrants::create(1, 42));
        self::assertSame('entrant-1', $first['id']);
        self::assertSame('rageux', $first['policy']);
        self::assertSame('grenouille', $second['policy']);
        self::assertNotSame($first['name'], $second['name']);
        self::assertSame(0, $first['glory']);
        self::assertSame(2000, $first['gold']);
        self::assertSame(0, array_sum($first['army']));
        self::assertSame(42, $first['joinedTick']);
        self::assertSame(0, $first['resetCount']);
    }
}
