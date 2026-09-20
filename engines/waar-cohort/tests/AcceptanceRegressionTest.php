<?php

use App\Game\Combat\CanonicalJson;
use App\Game\Combat\CombatEngine;
use App\Game\Combat\CombatReplay;
use App\Game\Combat\DemoRequestFactory;
use App\Infrastructure\Combat\RustCombatResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptanceRegressionTest extends TestCase
{
    private function rust(): RustCombatResolver
    {
        return new RustCombatResolver(dirname(__DIR__));
    }

    public function testAllDemoMonotypesMatchPhpIncludingProjectedBatchTotals(): void
    {
        $batch = DemoRequestFactory::batch(3);
        $native = $this->rust()->resolveBatch($batch);
        $php = new CombatEngine();
        foreach ($batch['scenarios'] as $index => $scenario) {
            $expected = ['attackerWins'=>0, 'defenderWins'=>0, 'draws'=>0, 'roundSum'=>0];
            foreach (['attacker', 'defender'] as $side) {
                $expected[$side.'RawDeathsByType'] = array_fill(0, 4, 0);
                $expected[$side.'RawWoundedByType'] = array_fill(0, 4, 0);
                $expected[$side.'ProjectedByType'] = array_fill(0, 4, array_fill(0, 4, 0));
            }
            for ($iteration = 0; $iteration < 3; ++$iteration) {
                $request = [
                    'schemaVersion'=>CombatEngine::REQUEST_SCHEMA, 'ruleset'=>$batch['ruleset'],
                    'attacker'=>$scenario['attacker'], 'defender'=>$scenario['defender'],
                    'seed'=>($batch['baseSeed'] + $scenario['seedKey'] * 1000003 + $iteration) % 2147483647,
                    'traceLevel'=>$iteration % 2 === 0 ? 'none' : 'full', 'consequences'=>$batch['consequences'],
                ];
                $report = $php->resolveRequest($request);
                self::assertSame(CanonicalJson::encode($report), CanonicalJson::encode($this->rust()->resolveRequest($request)), $scenario['id']);
                $winner = $report['result']['winner'];
                ++$expected[$winner === null ? 'draws' : $winner.'Wins'];
                $expected['roundSum'] += count($report['result']['rounds']);
                foreach (['attacker', 'defender'] as $side) {
                    foreach (['soldier', 'spearman', 'archer', 'knight'] as $typeIndex => $type) {
                        $expected[$side.'RawWoundedByType'][$typeIndex] += $report['result'][$side]['wounded'][$type];
                        $expected[$side.'RawDeathsByType'][$typeIndex] += $report['result'][$side]['dead'][$type];
                        foreach (['healthy', 'wounded', 'dead', 'prisoners'] as $categoryIndex => $category) {
                            $expected[$side.'ProjectedByType'][$typeIndex][$categoryIndex] += $report['consequences'][$side]['types'][$type]['projected'][$category];
                        }
                    }
                }
            }
            foreach ($expected as $key => $value) self::assertSame($value, $native['scenarios'][$index]['result'][$key], $scenario['id'].': '.$key);
        }
    }

    public static function largePopulations(): iterable
    {
        yield 'dead' => [50000000, '100', [0, 0, 50000000, 0]];
        yield 'wounded and captured' => [500000000, '1', [0, 250000000, 0, 250000000]];
    }

    #[DataProvider('largePopulations')]
    public function testLargeConsequencesMatchInDuelAndBatch(int $count, string $attack, array $categories): void
    {
        $request = DemoRequestFactory::combat('none');
        $request['attacker'] = ['units'=>['soldier'=>$count], 'modifiers'=>[]];
        $request['defender'] = $request['attacker'];
        $request['ruleset']['maxRounds'] = 1;
        foreach ($request['ruleset']['units'] as &$unit) {
            $unit['attack'] = $attack;
            $unit['structure'] = '100';
            $unit['baseAccuracy'] = '1';
            $unit['accuracySpread'] = '0';
            $unit['defendingEfficiency'] = '1';
        }
        unset($unit);
        $request['consequences'] = ['compressionPercent'=>100, 'capturePercent'=>50];
        $php = (new CombatEngine())->resolveRequest($request);
        self::assertSame(CanonicalJson::encode($php), CanonicalJson::encode($this->rust()->resolveRequest($request)));
        $projected = $php['consequences']['attacker']['types']['soldier']['projected'];
        self::assertSame($categories, array_map(static fn($key) => $projected[$key], ['healthy','wounded','dead','prisoners']));
        $batch = DemoRequestFactory::batch(1);
        $batch['ruleset'] = $request['ruleset'];
        $batch['consequences'] = $request['consequences'];
        $batch['scenarios'] = [['id'=>'large', 'seedKey'=>0, 'attacker'=>$request['attacker'], 'defender'=>$request['defender']]];
        $native = $this->rust()->resolveBatch($batch);
        self::assertSame($categories, $native['scenarios'][0]['result']['attackerProjectedByType'][0]);
    }

    public static function invalidPercentages(): iterable
    {
        yield ['compressionPercent', 101];
        yield ['capturePercent', 51];
    }

    #[DataProvider('invalidPercentages')]
    public function testBatchRejectsInvalidConsequences(string $key, int $value): void
    {
        $batch = DemoRequestFactory::batch(1);
        $batch['consequences'][$key] = $value;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compression must be 0..100 and capture 0..50 percent');
        $this->rust()->resolveBatch($batch);
    }

    public function testSerializedReportsReplayWithoutExternalRules(): void
    {
        foreach (['none', 'full'] as $trace) {
            $request = DemoRequestFactory::combat($trace);
            $request['ruleset']['maxRounds'] = 7;
            $request['ruleset']['surrender'] = ['enabled'=>true, 'deadRatio'=>'0.37'];
            $request['ruleset']['tieBreak'] = ['criterion'=>'structure', 'equality'=>'draw'];
            foreach ([new CombatEngine(), $this->rust()] as $engine) {
                $saved = json_decode(json_encode($engine->resolveRequest($request), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $replay = CombatReplay::request($saved);
                foreach ([new CombatEngine(), $this->rust()] as $replayer) {
                    self::assertSame(CanonicalJson::encode($saved), CanonicalJson::encode($replayer->resolveRequest($replay)));
                }
            }
        }
    }

    public function testReplayRejectsTamperedRules(): void
    {
        $report = (new CombatEngine())->resolveRequest(DemoRequestFactory::combat());
        $report['result']['ruleset']['maxRounds']++;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Replay inputs do not match');
        CombatReplay::request($report);
    }

    public function testReplayCliAcceptsSavedDemoAndRejectsAlteredResult(): void
    {
        $report = (new CombatEngine())->resolveRequest(DemoRequestFactory::combat());
        $path = tempnam(sys_get_temp_dir(), 'cohort-replay-');
        self::assertNotFalse($path);
        try {
            foreach ([false, true] as $tampered) {
                if ($tampered) $report['result']['winner'] = 'invalid';
                file_put_contents($path, CanonicalJson::encode(['combat'=>$report]));
                $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/replay.php', $path],
                    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($tampered ? 1 : 0, proc_close($process), $error);
                if ($tampered) self::assertStringContainsString('Replay differs', $error);
                else self::assertSame(CanonicalJson::encode($report), CanonicalJson::encode(json_decode($output, true, 512, JSON_THROW_ON_ERROR)));
            }
        } finally {
            unlink($path);
        }
    }
}
