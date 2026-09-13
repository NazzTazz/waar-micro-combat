<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\{BoundedProfileSearch, ConsequenceObjectives, EngineProfile, MonotypeMeasurementService};

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopRawCalibrationTest extends TestCase
{
    public function testOutputConsequencesDoNotMoveCalibrationPointsOrScores(): void
    {
        $service = new MonotypeMeasurementService();
        $baseline = null;
        $scores = null;
        $applied = [];
        foreach ([0, 8, 100] as $percent) {
            $profile = EngineProfile::defaults();
            $profile['combat']['lossCompressionPercent'] = $percent;
            $profile['combat']['capturePercent'] = $percent === 8 ? 10 : 0;
            $measurement = $service->measure($profile, 'neutral', 42, 1);
            $points = array_map(static fn($r) => [$r['id'], $r['winRate'], $r['rawLossRatio']], $measurement['rows']);
            $baseline ??= $points;
            self::assertSame($baseline, $points);
            $applied[$percent] = array_column($measurement['rows'], 'appliedLossRatio');
            $zones = array_map(static fn($r) => [
                'id'=>$r['id'], 'center'=>['x'=>$r['winRate'], 'y'=>$r['rawLossRatio']],
                'radii'=>['x'=>.05, 'y'=>.1], 'sourceFingerprint'=>$measurement['profileFingerprint'],
                'modelVersion'=>$measurement['modelVersion'], 'context'=>$measurement['context'],
            ], $measurement['rows']);
            $search = (new BoundedProfileSearch())->search($profile, $zones, 'neutral', 314159, 2, 1);
            $currentScores = array_column($search['candidates'], 'score');
            $scores ??= $currentScores;
            self::assertSame($scores, $currentScores);
            self::assertSame(0.0, $currentScores[0]);
        }
        self::assertNotSame($applied[0], $applied[8]);
        self::assertNotSame($applied[8], $applied[100]);
        unset($zones[0]['context']['objectiveMetric']);
        $this->expectException(\InvalidArgumentException::class);
        (new ConsequenceObjectives())->validate($profile, $zones, 'neutral', 42, 1);
    }
}
