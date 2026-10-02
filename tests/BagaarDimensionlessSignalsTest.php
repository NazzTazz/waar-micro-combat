<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\DimensionlessSignals;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarDimensionlessSignalsTest extends TestCase
{
    public function testSpreadsheetRowMatchesThePublishedFormulas(): void
    {
        $signals = DimensionlessSignals::from(40, 75, 325, 0, 1, 0, 1, 1, 8, 0, 3);

        self::assertSame(3, $signals['gloryRank']);
        self::assertSame(1.0, $signals['R_picsou']);
        self::assertSame(0.0, $signals['R_joy']);
        self::assertSame(1.0, $signals['R_rage']);
        self::assertSame(0.0, $signals['R_eq']);
        self::assertSame(0.0, $signals['R_rwaa']);
        self::assertEqualsWithDelta(0.012789768185451638, $signals['R_offense'], 1e-12);
        self::assertEqualsWithDelta(0.9872102318145484, $signals['R_defense'], 1e-12);
        self::assertEqualsWithDelta(1.0, $signals['R_picsou'] + $signals['R_eq'] + $signals['R_rwaa'], 1e-12);
        self::assertEqualsWithDelta(1.0, $signals['R_joy'] + $signals['R_rage'], 1e-12);
        self::assertEqualsWithDelta(1.0, $signals['R_offense'] + $signals['R_defense'], 1e-12);
    }

    public function testZeroFamilyIsSharedInsteadOfDividingByZero(): void
    {
        $signals = DimensionlessSignals::from(0, 0, 495, 250, 1, 0, 0, 1, 8, 1, 2);

        self::assertEqualsWithDelta(0.9979044007584074, $signals['R_eq'], 1e-12);
        self::assertEqualsWithDelta(0.0020955992415926557, $signals['R_rwaa'], 1e-12);
        self::assertSame(0.5, $signals['R_joy']);
        self::assertSame(0.5, $signals['R_rage']);
        self::assertSame(0.5, $signals['R_offense']);
        self::assertSame(0.5, $signals['R_defense']);
    }

    public function testEmptyRecordAndMineStayFinite(): void
    {
        $signals = DimensionlessSignals::from(0, 0, 100, 0, 0, 0, 0, 0, 0, 12, 1);

        self::assertSame(1, $signals['gloryRank']);
        self::assertEqualsWithDelta(1 / 3, $signals['R_picsou'], 1e-12);
        self::assertEqualsWithDelta(1 / 3, $signals['R_eq'], 1e-12);
        self::assertEqualsWithDelta(1 / 3, $signals['R_rwaa'], 1e-12);
        self::assertSame(0.5, $signals['R_joy']);
        self::assertSame(0.5, $signals['R_rage']);
        self::assertSame(0.5, $signals['R_offense']);
        self::assertSame(0.5, $signals['R_defense']);
    }
}
