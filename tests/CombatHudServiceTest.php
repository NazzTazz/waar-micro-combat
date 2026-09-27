<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\CombatHudService;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class CombatHudServiceTest extends TestCase
{
    public function testOneWaveContainsEveryMonotypeAndBothFreeArmyDirections(): void
    {
        $runtime = new class implements CohortRuntime {
            /** @var array<string,mixed> */
            public array $request = [];

            public function resolve(array $request): array
            {
                throw new \LogicException('Unused.');
            }

            public function batch(array $request): array
            {
                $this->request = $request;
                $rows = [];
                foreach ($request['scenarios'] as $scenario) {
                    $rows[] = [
                        'id' => $scenario['id'],
                        'armyIdentities' => $scenario['armyIdentities'],
                        'result' => [
                            'samples' => 50,
                            'attackerWins' => 30,
                            'draws' => 5,
                            'defenderWins' => 15,
                            'roundSum' => 100,
                            'attackerRawDeathsByType' => [0, 1, 2, 3],
                            'defenderRawDeathsByType' => [1, 2, 3, 4],
                            'attackerRawWoundedByType' => [2, 3, 4, 5],
                            'defenderRawWoundedByType' => [3, 4, 5, 6],
                            'attackerProjectedByType' => array_fill(0, 4, [0, 1, 2, 3]),
                            'defenderProjectedByType' => array_fill(0, 4, [0, 4, 5, 6]),
                        ],
                    ];
                }

                return [
                    'schemaVersion' => 'waar-combat-batch-result/2',
                    'modelVersion' => 'waar-cohort-v2',
                    'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION,
                    'unitOrder' => array_keys(EngineProfile::UNIT_COSTS),
                    'projectedCategoryOrder' => ['healthy', 'wounded', 'dead', 'prisoners'],
                    'totalCombats' => count($rows) * 50,
                    'consequenceProvenance' => [
                        'policyVersion' => CohortRequestFactory::POLICY_VERSION,
                        'samplingProtocol' => CohortRequestFactory::SAMPLING_PROTOCOL,
                        'compressionPercent' => $request['consequences']['compressionPercent'],
                        'capturePercent' => $request['consequences']['capturePercent'],
                    ],
                    'scenarios' => $rows,
                ];
            }

            public function provenance(): array
            {
                return ['kind' => 'fake', 'transport' => 'memory', 'modelVersion' => 'waar-cohort-v2'];
            }
        };
        $profile = EngineProfile::defaults();
        $profile['weather']['rain']['soldier']['attack'] = '0.9';
        $result = (new CombatHudService($runtime))->wave([
            'requestId' => 'wave-0',
            'profile' => $profile,
            'armies' => ['A' => ['soldier' => 10], 'B' => ['archer' => 7]],
            'weather' => 'rain',
            'modifiers' => ['A' => [], 'B' => []],
            'seed' => 42,
            'startIteration' => 0,
        ]);

        self::assertSame('waar-combat-campaign-batch-request/1', $runtime->request['schemaVersion']);
        self::assertSame(CohortRequestFactory::STOCHASTIC_VERSION, $runtime->request['stochasticEngineVersion']);
        self::assertSame(22, count($runtime->request['scenarios']));
        self::assertSame(1100, $result['totalCombats']);
        self::assertCount(18, $result['rows']);
        self::assertSame(['start' => 0, 'endExclusive' => 50, 'total' => 10000], $result['iterationRange']);

        $rows = array_column($result['rows'], null, 'id');
        self::assertSame(100, $rows['monotype:soldier>soldier']['samples']);
        self::assertSame(60, $rows['monotype:soldier>soldier']['attackerWins']);
        self::assertSame(50, $rows['monotype:soldier>archer']['samples']);
        self::assertSame(50, $rows['free:A>B']['samples']);
        self::assertSame(['dead' => 6, 'wounded' => 14], $rows['free:A>B']['attackerRaw']);
        self::assertSame(['dead' => 10, 'wounded' => 18], $rows['free:A>B']['defenderRaw']);
        self::assertSame(['dead' => 12, 'wounded' => 28], $rows['monotype:soldier>soldier']['attackerRaw']);
        self::assertSame(4, $rows['free:A>B']['attackerProjected']['wounded']);

        $free = array_column($runtime->request['scenarios'], null, 'id')['free:A>B'];
        self::assertSame('0.9', $free['attacker']['modifiers'][0]['value']);
        self::assertSame('0.9', $free['defender']['modifiers'][0]['value']);
        self::assertStringContainsString('rain', $free['attacker']['modifiers'][0]['id']);
        self::assertStringContainsString('rain', $free['defender']['modifiers'][0]['id']);

        $lastWave = (new CombatHudService($runtime))->wave([
            'requestId' => 'wave-last',
            'profile' => $profile,
            'armies' => ['A' => ['soldier' => 10], 'B' => ['archer' => 7]],
            'weather' => 'rain',
            'seed' => 42,
            'startIteration' => 9950,
        ]);
        self::assertSame(9950, $runtime->request['startIteration']);
        self::assertSame(10000, $runtime->request['totalIterations']);
        self::assertSame(['start' => 9950, 'endExclusive' => 10000, 'total' => 10000], $lastWave['iterationRange']);
    }

    public function testRejectsInputsThatWouldMakeTheHudAmbiguous(): void
    {
        $service = new CombatHudService(new class implements CohortRuntime {
            public function resolve(array $request): array { return []; }
            public function batch(array $request): array { throw new \LogicException('Must not run.'); }
            public function provenance(): array { return ['kind' => 'fake', 'transport' => 'memory', 'modelVersion' => 'waar-cohort-v2']; }
        });
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dragon');
        $service->wave([
            'requestId' => 'invalid',
            'profile' => EngineProfile::defaults(),
            'armies' => ['A' => ['soldier' => 1, 'dragon' => 1], 'B' => ['soldier' => 1]],
            'weather' => 'neutral',
            'seed' => 1,
            'startIteration' => 0,
        ]);
    }
}
