<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\DimensionlessSignals;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarDimensionlessSignalsTest extends TestCase
{
    public function testSpreadsheetRowKeepsRawTerms(): void
    {
        $signals = DimensionlessSignals::from(40, 75, 325, 0, 1, 0, 1, 1, 8, 0, 3, 2);

        self::assertSame(DimensionlessSignals::VERSION, $signals['signalsVersion']);
        self::assertSame(3, $signals['gloryRank']);
        self::assertSame(2, $signals['activeGloryRank']);
        self::assertEqualsWithDelta(21.381578947368421, $signals['R_picsou'], 1e-12);
        self::assertSame(0.0, $signals['R_joy']);
        self::assertEqualsWithDelta(6.859756097560976, $signals['R_rage'], 1e-12);
        self::assertEqualsWithDelta(0.5263157894736842, $signals['R_eq'], 1e-12);
        self::assertSame(0.0, $signals['R_rwaa']);
        self::assertEqualsWithDelta(0.2631578947368421, $signals['R_offense'], 1e-12);
        self::assertSame(20.3125, $signals['R_defense']);
        self::assertNotEqualsWithDelta(1.0, $signals['R_picsou'] + $signals['R_eq'] + $signals['R_rwaa'], 1e-9);
        self::assertTrue($signals['defined']['R_picsou']);
        self::assertTrue($signals['defined']['R_joy']);
    }

    public function testScaledInputsDoNotCollapseToTheSameShare(): void
    {
        $small = DimensionlessSignals::from(10, 0, 10, 0, 1, 0, 0, 1, 10, 20, 1, 1);
        $large = DimensionlessSignals::from(1000, 0, 10, 0, 1, 0, 0, 1, 10, 20, 1, 1);

        self::assertSame(10.0, $small['R_picsou']);
        self::assertSame(1000.0, $large['R_picsou']);
    }

    public function testAWinWithNoLossesKeepsMeasuredZeros(): void
    {
        $signals = DimensionlessSignals::from(0, 0, 495, 250, 1, 0, 0, 1, 8, 1, 2, 1);

        self::assertSame(0.0, $signals['R_picsou']);
        self::assertSame(0.0, $signals['R_joy']);
        self::assertSame(0.0, $signals['R_rage']);
        self::assertEqualsWithDelta(476.1904761904762, $signals['R_eq'], 1e-12);
        self::assertSame(1.0, $signals['R_rwaa']);
        self::assertSame(0.0, $signals['R_offense']);
        self::assertSame(0.0, $signals['R_defense']);
    }

    public function testEmptyRecordAndMineStayUndefined(): void
    {
        $signals = DimensionlessSignals::from(0, 0, 100, 0, 0, 0, 0, 0, 0, 12, 1, null);

        self::assertSame(1, $signals['gloryRank']);
        self::assertNull($signals['activeGloryRank']);
        foreach (['R_picsou', 'R_eq', 'R_rwaa', 'R_joy', 'R_rage', 'R_offense', 'R_defense'] as $key) {
            self::assertNull($signals[$key]);
            self::assertFalse($signals['defined'][$key]);
        }
    }
}
