<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\MixedCompositionObservationPlanBuilder;
use Waar\MicroCombat\Experiment\MixedCompositionObservationPresentationBuilder;
use Waar\MicroCombat\Experiment\MixedCompositionObservationRenderer;
use Waar\MicroCombat\Experiment\MixedCompositionObservationRunner;

require_once dirname(__DIR__).'/autoload.php';

final class MixedCompositionObservationTest extends TestCase
{
    private string $root;
    private string $t24;
    private string $t33;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->t24 = dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json';
        $this->t33 = $this->root.'/experiments/references/t33-finalist-stability';
    }

    public function testPlanPreservesExactT24CorpusBudgetsCandidatesAndPolicies(): void
    {
        $plan = (new MixedCompositionObservationPlanBuilder())->build($this->t24, $this->t33);
        $source = json_decode((string) file_get_contents($this->t24), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('planned-before-measurement', $plan['state']);
        self::assertFalse($plan['measurementStarted']);
        self::assertSame(32_452_843, $plan['sampling']['baseSeed']);
        self::assertSame(1_000, $plan['sampling']['iterationsPerScenarioAndVariant']);
        self::assertSame(array_column($source['scenarios'], 'id'), array_column($plan['corpus']['scenarios'], 'id'));
        foreach ($source['scenarios'] as $index => $scenario) {
            foreach (['id', 'label', 'focusSide'] as $field) {
                self::assertSame($scenario[$field], $plan['corpus']['scenarios'][$index][$field]);
            }
            foreach (['attacker', 'defender'] as $side) {
                $expectedArmy = [];
                foreach (['soldier', 'spearman', 'archer', 'knight'] as $type) {
                    $expectedArmy[$type] = $scenario[$side][$type] ?? 0;
                }
                self::assertSame($expectedArmy, $plan['corpus']['scenarios'][$index][$side]);
            }
        }
        self::assertSame([
            ['attacker' => 2400, 'defender' => 2400],
            ['attacker' => 2800, 'defender' => 2480],
            ['attacker' => 4460, 'defender' => 2940],
            ['attacker' => 3820, 'defender' => 2940],
            ['attacker' => 4300, 'defender' => 3140],
            ['attacker' => 4760, 'defender' => 4760],
        ], array_column($plan['corpus']['scenarios'], 'budgets'));
        self::assertSame(['roles-a', 't31-candidate-0116-d0c5f6473d05', 't31-candidate-0128-96382d7e8496', 't31-candidate-0123-3805dc464a60'], array_column($plan['variants'], 'id'));
        self::assertSame([null, 1, 2, 3], array_column($plan['variants'], 't31Order'));
        self::assertSame(['objectifs-non-atteints'], array_values(array_unique(array_column($plan['variants'], 't33Status'))));
        self::assertSame('defender', $plan['combatPolicy']['tieBreakPolicy']);
        self::assertSame('draw', $plan['historicalReference']['tieBreakPolicy']);
        self::assertFalse($plan['historicalReference']['displayedAsMeasurement']);
        self::assertTrue($plan['interpretation']['t24CorpusExcludedFromT31Scoring']);
    }

    public function testChangedT24CorpusIsRejectedBeforePlanning(): void
    {
        $path = sys_get_temp_dir().'/waar-t34-corpus-'.bin2hex(random_bytes(6)).'.json';
        copy($this->t24, $path);
        file_put_contents($path, "\n", FILE_APPEND);
        try {
            (new MixedCompositionObservationPlanBuilder())->build($path, $this->t33, 1, 1);
            self::fail('Changed corpus should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('accepted SHA-256', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testSmallRunPublishesBothSidesMixedMetricsUnitLossesAndOnlyObservations(): void
    {
        $plan = (new MixedCompositionObservationPlanBuilder())->build($this->t24, $this->t33, 3, 12_345);
        $temporary = sys_get_temp_dir().'/waar-t34-inputs-'.bin2hex(random_bytes(6));
        mkdir($temporary, 0777, true);
        copy($this->t24, $temporary.'/t24-corpus.json');
        foreach ($plan['variants'] as $index => $candidate) {
            copy($candidate['sourcePath'], sprintf('%s/candidate-%02d.json', $temporary, $index));
        }
        try {
            $events = [];
            $result = (new MixedCompositionObservationRunner())->run($plan, $temporary, static function (int $index, int $count) use (&$events): void { $events[] = [$index, $count]; });
            self::assertSame('completed', $result['state']);
            self::assertSame(72, $result['execution']['actualCombatCount']);
            self::assertSame(108, $result['execution']['logicalComparisonCombatCount']);
            self::assertSame([[1, 3], [2, 3], [3, 3]], $events);
            self::assertCount(12, $result['initial']['rows']);
            self::assertCount(3, $result['finalists']);
            foreach ([$result['initial'], ...$result['finalists']] as $candidate) {
                self::assertSame(['attacker', 'defender'], array_values(array_unique(array_column($candidate['rows'], 'side'))));
                foreach ($candidate['rows'] as $row) {
                    self::assertSame($row['iterations'], $row['wins'] + $row['losses'] + $row['draws']);
                    foreach ($row['units'] as $unit) {
                        self::assertSame($unit['initial'], $unit['survivors'] + $unit['losses']);
                        self::assertEqualsWithDelta($unit['meanInitial'], $unit['meanSurvivors'] + $unit['meanLosses'], 1e-12);
                    }
                }
            }
            $mixed = array_filter($result['finalists'][0]['rows'], static fn (array $row): bool => 'mixed-armies' === $row['scenarioId']);
            self::assertNotEmpty(array_filter($mixed, static fn (array $row): bool => abs($row['metrics']['survivors'] - $row['metrics']['economicValue']) > 1e-12), 'Mixed survivor and economic ratios must be calculated independently.');
            self::assertCount(5, $result['observations']['largestAbsoluteGaps']);
            self::assertFalse($result['interpretation']['selectionPerformed']);
            self::assertTrue($result['interpretation']['monotypeFailureStillApplies']);
            self::assertStringNotContainsString('continuousLoss', json_encode($result, JSON_THROW_ON_ERROR));

            $presentation = (new MixedCompositionObservationPresentationBuilder())->build($plan, $result, str_repeat('a', 64));
            self::assertCount(3, $presentation['axes']['y']);
            self::assertCount(6, $presentation['scenarios']);
            self::assertSame('objectifs-non-atteints', $presentation['finalists'][0]['t33Status']);
            $resources = dirname(__DIR__).'/resources';
            $renderer = new MixedCompositionObservationRenderer();
            $html = $renderer->html($presentation, (string) file_get_contents($resources.'/mixed-composition-observation.html'), (string) file_get_contents($resources.'/vendor/echarts-5.6.0.min.js'), (string) file_get_contents($resources.'/mixed-composition-observation-model.js'), (string) file_get_contents($resources.'/mixed-composition-observation-app.js'));
            self::assertStringContainsString('Survivants et pertes par type', $html);
            self::assertStringContainsString('Aucun score d’acceptation T24', $html);
            self::assertStringNotContainsString('__OBSERVATION_JSON__', $html);
            self::assertStringContainsString('Plus grands écarts absolus', $renderer->markdown($presentation));
        } finally {
            $this->removeDirectory($temporary);
        }
    }

    public function testFrozenCandidateMutationIsRejected(): void
    {
        $plan = (new MixedCompositionObservationPlanBuilder())->build($this->t24, $this->t33, 1, 7);
        $temporary = sys_get_temp_dir().'/waar-t34-freeze-'.bin2hex(random_bytes(6));
        mkdir($temporary, 0777, true);
        copy($this->t24, $temporary.'/t24-corpus.json');
        foreach ($plan['variants'] as $index => $candidate) {
            copy($candidate['sourcePath'], sprintf('%s/candidate-%02d.json', $temporary, $index));
        }
        file_put_contents($temporary.'/candidate-01.json', "\n", FILE_APPEND);
        try {
            (new MixedCompositionObservationRunner())->verifyInputs($plan, $temporary);
            self::fail('Changed candidate should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('changed after planning', $exception->getMessage());
        } finally {
            $this->removeDirectory($temporary);
        }
    }

    public function testOfficialPlanSemanticsRemainUnchanged(): void
    {
        $plan = (new MixedCompositionObservationPlanBuilder())->build($this->t24, $this->t33);
        $expected = $this->json($this->root.'/experiments/references/t34-mixed-composition-observation/observation-plan.json');
        unset($expected['frozenCopies']);
        $plan['corpus']['sourcePath'] = $expected['corpus']['sourcePath'];
        foreach ($plan['variants'] as $index => &$variant) $variant['sourcePath'] = $expected['variants'][$index]['sourcePath'];
        unset($variant);
        self::assertSame($expected, $plan);
    }

    public function testAlteredT33PlanBytesAreRejected(): void
    {
        foreach (["\n", 'seed'] as $mutation) {
            $directory = $this->copyT33();
            try {
                $path = $directory.'/validation-plan.json';
                if ('seed' === $mutation) {
                    $plan = $this->json($path);
                    $plan['sampling']['baseSeeds'][0] = 42;
                    $this->writeJson($path, $plan);
                } else {
                    file_put_contents($path, $mutation, FILE_APPEND);
                }
                $this->assertProvenanceRejected($directory, 'T33 plan SHA-256');
            } finally {
                $this->removeDirectory($directory);
            }
        }
    }

    public function testRequiredProvenanceHashesAreValidated(): void
    {
        foreach (['planLink', 'candidatePlan', 'candidateResult', 'copy'] as $location) {
            foreach ([null, 123, [], '', str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 64)] as $invalid) {
                foreach ('planLink' === $location ? [0] : [0, 1, 2, 3] as $index) {
                    $directory = $this->copyT33();
                    try {
                        $plan = $this->json($directory.'/validation-plan.json');
                        $result = $this->json($directory.'/result.json');
                        if ('candidatePlan' === $location) {
                            $field = &$plan['candidates'][$index];
                        } elseif ('candidateResult' === $location) {
                            $field = &$result['candidates'][$index];
                        } elseif ('copy' === $location) {
                            $field = &$plan['frozenCopies'][sprintf('candidate-%02d', $index)];
                        } else {
                            $field = &$result;
                        }
                        $key = 'planLink' === $location ? 'planSha256' : 'sha256';
                        if (null === $invalid) unset($field[$key]);
                        else $field[$key] = $invalid;
                        unset($field);
                        $this->writeJson($directory.'/validation-plan.json', $plan);
                        if ('planLink' !== $location) $result['planSha256'] = hash_file('sha256', $directory.'/validation-plan.json');
                        $this->writeJson($directory.'/result.json', $result);
                        $this->assertProvenanceRejected($directory, 'planLink' === $location ? 'T33 plan SHA-256' : 'candidate SHA-256');
                    } finally {
                        $this->removeDirectory($directory);
                    }
                }
            }
        }
    }

    public function testCopyAndCopyHashCannotOverrideCandidateDeclarations(): void
    {
        $directory = $this->copyT33();
        try {
            $plan = $this->json($directory.'/validation-plan.json');
            $copy = &$plan['frozenCopies']['candidate-01'];
            file_put_contents($directory.'/'.$copy['path'], "\n", FILE_APPEND);
            $copy['sha256'] = hash_file('sha256', $directory.'/'.$copy['path']);
            unset($copy);
            $this->writeJson($directory.'/validation-plan.json', $plan);
            $result = $this->json($directory.'/result.json');
            $result['planSha256'] = hash_file('sha256', $directory.'/validation-plan.json');
            $this->writeJson($directory.'/result.json', $result);
            $this->assertProvenanceRejected($directory, 'candidate SHA-256');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testCliRejectsInconsistentT33BeforeProducingArtifacts(): void
    {
        $directory = $this->copyT33();
        try {
            file_put_contents($directory.'/validation-plan.json', "\n", FILE_APPEND);
            $process = proc_open([PHP_BINARY, $this->root.'/bin/observe-mixed-compositions.php', $this->t24, $directory, $directory.'/output', '1', '7'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertNotSame(0, proc_close($process));
            self::assertStringContainsString('T33 plan SHA-256', $stderr);
            self::assertStringNotContainsString('manifeste', $stderr);
            self::assertSame('', $stdout);
            self::assertSame([], array_values(array_diff(scandir($directory.'/output') ?: [], ['.', '..'])));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function copyT33(): string
    {
        $directory = sys_get_temp_dir().'/waar-r1-'.bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        foreach (['validation-plan.json', 'result.json'] as $name) copy($this->t33.'/'.$name, $directory.'/'.$name);
        foreach ($this->json($directory.'/validation-plan.json')['frozenCopies'] as $copy) {
            $path = $directory.'/'.$copy['path'];
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
            copy($this->t33.'/'.$copy['path'], $path);
        }
        return $directory;
    }

    private function json(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeJson(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }

    private function assertProvenanceRejected(string $directory, string $message): void
    {
        try {
            (new MixedCompositionObservationPlanBuilder())->build($this->t24, $directory, 1, 7);
            self::fail('Inconsistent provenance was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
