<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\BoundedProfileSearch;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopAdvancedTest extends TestCase
{
    public function testMonotypeMeasurementHasExplicitLossAxisAndStableContext(): void
    {
        $profile = EngineProfile::defaults();
        $result = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        self::assertSame('waar-monotype-consequence-observations/0.2', $result['schemaVersion']);
        self::assertSame(EngineProfile::MODEL_VERSION, $result['modelVersion']);
        self::assertCount(32, $result['rows']);
        self::assertSame(16, array_sum(array_map(static fn (array $r): int => $r['iterations'], $result['rows'])) / 2);
        self::assertSame(['weather' => 'neutral', 'baseSeed' => 42, 'iterations' => 1, 'budget' => 400400, 'objectiveMetric' => 'rawCasualtyRatio'], array_intersect_key($result['context'], array_flip(['weather', 'baseSeed', 'iterations', 'budget', 'objectiveMetric'])));
        self::assertSame('rust', $result['context']['runtime']['kind']);
        self::assertSame(16, $result['batch']['totalCombats']);
        foreach ($result['rows'] as $row) {
            self::assertArrayHasKey('rawCasualtyRatio', $row);
            self::assertArrayHasKey('drawRate', $row);
            self::assertArrayNotHasKey('survivors', $row);
            self::assertGreaterThanOrEqual(0, $row['rawCasualtyRatio']);
            self::assertLessThanOrEqual(1, $row['rawCasualtyRatio']);
            self::assertLessThanOrEqual(1, $row['winRate'] + $row['drawRate']);
        }
    }

    public function testSearchIsBoundedDeterministicAndDoesNotSelect(): void
    {
        $profile = EngineProfile::defaults();
        $measure = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        $zones = array_map(static fn (array $r): array => ['sourceFingerprint' => $measure['profileFingerprint'], 'modelVersion' => $measure['modelVersion'], 'context' => $measure['context'], 'id' => $r['id'], 'center' => ['x' => $r['winRate'], 'y' => $r['rawCasualtyRatio']], 'radii' => ['x' => .05, 'y' => .1]], $measure['rows']);
        $search = new BoundedProfileSearch();
        $a = $search->search($profile, $zones, 'neutral', 314159, 2, 1, [], 42);
        $b = $search->search($profile, $zones, 'neutral', 314159, 2, 1);
        self::assertSame($a, $b);
        self::assertSame(2, $a['evaluated']);
        self::assertFalse($a['selectionPerformed']);
        self::assertSame(8 >= $a['candidateBudget'], true);
        self::assertSame(1, $a['candidates'][0]['rank']);
        self::assertSame(32, $a['candidates'][0]['total']);
    }

    public function testSearchSupportsNoRelationsAndMoreThanThreeRelations(): void
    {
        foreach ([[], [['acting' => 'soldier', 'target' => 'spearman', 'factor' => '1.2'], ['acting' => 'spearman', 'target' => 'knight', 'factor' => '1.5'], ['acting' => 'archer', 'target' => 'soldier', 'factor' => '1.1'], ['acting' => 'knight', 'target' => 'archer', 'factor' => '1.4']]] as $relations) {
            $profile = EngineProfile::defaults();
            $profile['relations'] = $relations;
            $measure = (new MonotypeMeasurementService())->measure($profile, 'neutral', 1, 1);
            $zones = array_map(static fn (array $r): array => ['sourceFingerprint' => $measure['profileFingerprint'], 'modelVersion' => $measure['modelVersion'], 'context' => $measure['context'], 'id' => $r['id'], 'center' => ['x' => $r['winRate'], 'y' => $r['rawCasualtyRatio']], 'radii' => ['x' => .1, 'y' => .1]], $measure['rows']);
            $result = (new BoundedProfileSearch())->search($profile, $zones, 'neutral', 2, 8, 1, [], 1);
            self::assertSame(8, $result['evaluated']);
            self::assertCount(12 + count($relations), $result['bounds']);
            if ($relations) {
                self::assertNotEmpty(array_filter($result['candidates'], static fn (array $candidate): bool => $candidate['profile']['relations'] !== $relations));
            }
        }
    }
}
