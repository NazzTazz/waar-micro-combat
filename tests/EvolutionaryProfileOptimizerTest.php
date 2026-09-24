<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\EvolutionaryProfileOptimizer;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;

require_once dirname(__DIR__).'/autoload.php';

final class EvolutionaryProfileOptimizerTest extends TestCase
{
    public function testSecondGenerationDescendsFromMeasuredParentsAndImproves(): void
    {
        $profile = EngineProfile::defaults();
        $measurement = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        $zones = $this->shiftedZones($measurement);
        $result = (new EvolutionaryProfileOptimizer())->optimize($profile, $zones, 'neutral', 314159, 16, 1, [], 42);
        self::assertSame('waar-optimizer-report/1', $result['schemaVersion']);
        self::assertSame(16, $result['evaluated']);
        self::assertCount(2, $result['generations']);
        self::assertLessThan($result['generations'][0]['best']['metrics']['score'], $result['generations'][1]['best']['metrics']['score']);
        $first = $result['generations'][0]['candidateIds'];
        $second = array_filter($result['candidates'], static fn (array$c): bool => $c['generation'] === 2);
        self::assertNotEmpty($second);
        foreach ($second as $candidate) {
            self::assertNotEmpty(array_intersect($first, $candidate['parentIds']));
        }
        self::assertNotEmpty(array_filter($result['candidates'], static fn (array$c): bool => count($c['mutations']) > 1));
        self::assertArrayHasKey('units.soldier.baseAccuracy', $result['bounds']);
        self::assertArrayHasKey('relations.soldier.spearman.factor', $result['bounds']);
        self::assertFalse($result['selectionPerformed']);
        self::assertSame($profile['units']['soldier']['cost'], $result['referenceProfile']['units']['soldier']['cost'], 'optimizer preserves the supplied costs');
    }

    public function testGenerationIsDeterministicAndMakesImplicitRelationsSearchable(): void
    {
        $profile = EngineProfile::defaults();
        $measurement = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        $zones = $this->shiftedZones($measurement);
        $optimizer = new EvolutionaryProfileOptimizer();
        $a = $optimizer->optimize($profile, $zones, 'neutral', 123, 8, 1, [], 42);
        $b = $optimizer->optimize($profile, $zones, 'neutral', 123, 8, 1, [], 42);
        self::assertSame(array_column($a['candidates'], 'fingerprint'), array_column($b['candidates'], 'fingerprint'));
        self::assertCount(28, $a['bounds']);
        self::assertSame('1', $a['bounds']['relations.archer.knight.factor']['current']);
        self::assertNotSame(array_column($a['candidates'], 'fingerprint'), array_column($optimizer->optimize($profile, $zones, 'neutral', 124, 8, 1, [], 42)['candidates'], 'fingerprint'));
    }

    private function shiftedZones(array $measurement): array
    {
        return array_map(static fn (array$row): array => ['sourceFingerprint' => $measurement['profileFingerprint'], 'modelVersion' => $measurement['modelVersion'], 'context' => $measurement['context'], 'id' => $row['id'],
            'center' => ['x' => $row['winRate'] > .5 ? .8 : .2, 'y' => max(.01, min(.99, $row['rawCasualtyRatio'] * .7))], 'radii' => ['x' => .1, 'y' => .15]], $measurement['rows']);
    }
}
