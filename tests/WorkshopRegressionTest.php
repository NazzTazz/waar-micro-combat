<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\BoundedProfileSearch;
use Waar\MicroCombat\Workshop\ConsequenceObjectives;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopRegressionTest extends TestCase
{
    private function zones(array $measurement): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row['id'], 'center' => ['x' => $row['winRate'], 'y' => $row['rawCasualtyRatio']],
            'radii' => ['x' => .05, 'y' => .1], 'sourceFingerprint' => $measurement['profileFingerprint'],
            'modelVersion' => $measurement['modelVersion'], 'context' => $measurement['context'],
        ], $measurement['rows']);
    }

    public function testReferenceIsBitExactRegardlessOfSearchSeed(): void
    {
        $profile = EngineProfile::defaults();
        $measurement = (new MonotypeMeasurementService())->measure($profile, 'rain', 42, 10);
        foreach ([314159, 123] as $searchSeed) {
            $result = (new BoundedProfileSearch())->search($profile, $this->zones($measurement), 'rain', $searchSeed, 1, 10, [], 42);
            self::assertSame($measurement, $result['candidates'][0]['observations']);
            self::assertSame(0.0, (float)$result['candidates'][0]['score']);
            self::assertSame(32, $result['candidates'][0]['inside']);
            self::assertSame(42, $result['measurementBaseSeed']);
        }
    }

    public function testRejectsForeignOrMalformedObjectivesBeforeSearch(): void
    {
        $profile = EngineProfile::defaults();
        $measurement = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        $zones = $this->zones($measurement);
        foreach (['profile', 'seed', 'weather', 'iterations', 'model', 'previous-model', 'unknown', 'duplicate', 'radius', 'missing'] as $case) {
            $bad = $zones;
            switch ($case) {
                case 'profile': $bad[0]['sourceFingerprint'] = 'foreign';
                    break;
                case 'seed': $bad[0]['context']['baseSeed'] = 314159;
                    break;
                case 'weather': $bad[0]['context']['weather'] = 'rain';
                    break;
                case 'iterations': $bad[0]['context']['iterations'] = '1';
                    break;
                case 'model': $bad[0]['modelVersion'] = 'foreign';
                    break;
                case 'previous-model': $bad[0]['modelVersion'] = 'waar-micro-combat/consequences-v1';
                    break;
                case 'unknown': $bad[0]['id'] = 'unknown';
                    break;
                case 'duplicate': $bad[1] = $bad[0];
                    break;
                case 'radius': $bad[0]['radii']['x'] = 0;
                    break;
                case 'missing': unset($bad[0]['context']);
                    break;
            }
            try {
                (new BoundedProfileSearch())->search($profile, $bad, 'neutral', 314159, 1, 1);
                self::fail($case);
            } catch (\InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage(), $case);
            }
        }
        self::assertSame($zones, (new ConsequenceObjectives())->validate($profile, $zones, 'neutral', 42, 1));
    }

    public function testDraftRoundTripAndDuelDoNotRequireUnusedUnits(): void
    {
        $draft = EngineProfile::defaults();
        $draft['units']['archer'] = null;
        self::assertSame([], EngineProfile::validate($draft, []));
        $reloaded = json_decode(json_encode($draft, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($draft, $reloaded);
        $duel = (new DuelService())->simulate(['profile' => $reloaded, 'armies' => ['A' => ['soldier' => 100], 'B' => ['soldier' => 100]], 'seed' => 42]);
        self::assertCount(2, $duel['directions']);
        self::assertSame('missing_unit', EngineProfile::validate($reloaded)[0]['code']);
        $draft['units']['soldier']['attack'] = 'invalid';
        self::assertSame('invalid_decimal', EngineProfile::validate($draft, [])[0]['code']);
    }
}
