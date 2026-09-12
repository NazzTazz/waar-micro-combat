<?php

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\LegacyMonotypePitchCoordinates;

final class LegacyMonotypePitchCoordinatesTest extends TestCase
{
    public function testLossDilationKeepsCandidateAndWinRatesUnchanged(): void
    {
        $point = (new LegacyMonotypePitchCoordinates())->build(1,.98,.65,.55);
        self::assertEqualsWithDelta(40,$point['legacy'][1],1e-10);
        self::assertEqualsWithDelta(45,$point['candidate'][1],1e-10);
        self::assertEquals([100,40],array_map(static fn($n)=>round($n),$point['legacy']));
        self::assertEquals(65,$point['candidate'][0]);
        self::assertEqualsWithDelta(2,$point['legacyRawLoss'],1e-10);
        self::assertEqualsWithDelta(105,$point['ellipse'][0][0],1e-10);
        self::assertEqualsWithDelta(50,$point['ellipse'][20][1],1e-10);
    }

    public function testLossIndexAboveOneHundredIsNotClamped(): void
    {
        $point = (new LegacyMonotypePitchCoordinates())->build(0,.94,1,1);
        self::assertEqualsWithDelta(120,$point['legacy'][1],1e-10);
        self::assertSame(0.0,$point['candidate'][1]);
    }

    public function testInvalidObservationRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyMonotypePitchCoordinates())->build(0,NAN,1,1);
    }
}
