<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentReportAggregator;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\FinalistStabilityAnalyzer;
use Waar\MicroCombat\Experiment\FinalistStabilityPlanBuilder;
use Waar\MicroCombat\Experiment\FinalistStabilityPresentationBuilder;
use Waar\MicroCombat\Experiment\FinalistStabilityRunner;

require_once dirname(__DIR__).'/autoload.php';

final class FinalistStabilityTest extends TestCase
{
    private string $runDirectory;

    protected function setUp(): void
    {
        $this->runDirectory = dirname(__DIR__).'/experiments/references/t31-standard-seed-314159';
    }

    public function testOfficialPlanFreezesOrderedCandidatesAndSeparatesEverySeed(): void
    {
        $plan = (new FinalistStabilityPlanBuilder())->buildFromDirectory(
            $this->runDirectory,
            [104729, 130363, 155921, 180749, 205759],
        );

        self::assertSame('planned-before-measurement', $plan['state']);
        self::assertFalse($plan['measurementStarted']);
        self::assertSame([104729, 130363, 155921, 180749, 205759], $plan['sampling']['baseSeeds']);
        self::assertSame([], $plan['seedSeparation']['corrections']);
        self::assertSame(0, $plan['seedSeparation']['collisionsAfterCorrection']);
        self::assertSame(42, $plan['seedSeparation']['searchBaseSeed']);
        self::assertSame(200, $plan['seedSeparation']['searchIterationsPerScenario']);
        self::assertSame(80_000, $plan['seedSeparation']['validationDerivedSeedCount']);
        self::assertSame(['roles-a', 't31-candidate-0116-d0c5f6473d05', 't31-candidate-0128-96382d7e8496', 't31-candidate-0123-3805dc464a60'], array_column($plan['candidates'], 'id'));
        self::assertSame([null, 1, 2, 3], array_column($plan['candidates'], 'rank'));
    }

    public function testCollisionCreatesDeterministicCorrectedPlanBeforeMeasurement(): void
    {
        $builder = new FinalistStabilityPlanBuilder();
        $first = $builder->separateSeeds(['scenario'], 42, 3, [42, 42], 3);
        $second = $builder->separateSeeds(['scenario'], 42, 3, [42, 42], 3);

        self::assertSame($first, $second);
        self::assertNotSame([42, 42], $first['effectiveBaseSeeds']);
        self::assertNotEmpty($first['audit']['corrections']);
        self::assertSame(0, $first['audit']['collisionsAfterCorrection']);
    }

    public function testAggregationSumsCountersAndIsReproducible(): void
    {
        $source = ExperimentDefinition::fromFile($this->runDirectory.'/experiment.json');
        $runner = new ExperimentRunner();
        $reports = [];
        foreach ([104729, 130363] as $seed) {
            $experiment = new ExperimentDefinition($source->id, $source->label, 3, $seed, $source->baseline, $source->candidate, $source->scenarios);
            $reports[] = $runner->run($experiment);
        }
        $aggregator = new ExperimentReportAggregator();
        $aggregate = $aggregator->aggregate($reports);

        self::assertSame($aggregate, $aggregator->aggregate($reports));
        self::assertSame(6, $aggregate['experiment']['iterations']);
        self::assertSame([104729, 130363], $aggregate['experiment']['baseSeeds']);
        self::assertNull($aggregate['experiment']['baseSeed']);
        self::assertSame(192, $aggregate['experiment']['combatCount']);
        foreach ($aggregate['rows'] as $index => $row) {
            foreach (['baseline', 'candidate'] as $variant) {
                self::assertSame($reports[0]['rows'][$index][$variant]['wins'] + $reports[1]['rows'][$index][$variant]['wins'], $row[$variant]['wins']);
                self::assertSame($reports[0]['rows'][$index][$variant]['metrics']['survivors']['numerator'] + $reports[1]['rows'][$index][$variant]['metrics']['survivors']['numerator'], $row[$variant]['metrics']['survivors']['numerator']);
            }
        }
    }

    public function testAllFourDescriptiveStatuses(): void
    {
        $analyzer = new FinalistStabilityAnalyzer();
        $outside = $this->evaluation(false, 'outside', 0);
        $inside = $this->evaluation(true, 'inside', 0);

        self::assertSame('stable-sur-les-lots', $analyzer->analyze([$inside, $inside], $inside)['status']);
        self::assertSame('variable-selon-le-lot', $analyzer->analyze([$inside, $outside], $outside)['status']);
        self::assertSame('objectifs-non-atteints', $analyzer->analyze([$outside, $outside], $outside)['status']);
        self::assertSame('invariant-nuls-en-echec', $analyzer->analyze([$inside, $this->evaluation(false, 'outside', 1)], $outside)['status']);
    }

    public function testSmallValidationMatchesInputsAndCombatAccounting(): void
    {
        $builder = new FinalistStabilityPlanBuilder();
        $plan = $builder->buildFromDirectory($this->runDirectory, [104729, 130363], 2);
        $events = [];
        $result = (new FinalistStabilityRunner())->run($plan, $this->runDirectory, static function (string $event, int $batch, ?int $candidate, int $candidateCount) use (&$events): void {
            $events[] = [$event, $batch, $candidate, $candidateCount];
        });

        self::assertSame('completed', $result['state']);
        self::assertSame(320, $result['execution']['actualCombatCount']);
        self::assertSame(512, $result['execution']['logicalReportCombatCount']);
        self::assertTrue($result['inputIntegrity']['verifiedAfterMeasurement']);
        self::assertFalse($result['order']['opportunisticRankingPerformed']);
        self::assertSame(array_column($plan['candidates'], 'id'), array_column($result['candidates'], 'id'));
        self::assertSame([
            ['batch-start', 1, null, 4],
            ['candidate-complete', 1, 1, 4],
            ['candidate-complete', 1, 2, 4],
            ['candidate-complete', 1, 3, 4],
            ['candidate-complete', 1, 4, 4],
            ['batch-complete', 1, null, 4],
            ['batch-start', 2, null, 4],
            ['candidate-complete', 2, 1, 4],
            ['candidate-complete', 2, 2, 4],
            ['candidate-complete', 2, 3, 4],
            ['candidate-complete', 2, 4, 4],
            ['batch-complete', 2, null, 4],
        ], $events, 'Each batch timer starts before candidate one and ends after candidate four.');
        foreach ($result['candidates'] as $candidate) {
            self::assertCount(2, $candidate['batches']);
            self::assertCount(32, $candidate['aggregate']['report']['rows']);
            self::assertCount(32, $candidate['aggregate']['evaluation']['entries']);
            self::assertCount(32, $candidate['variationBetweenBatches']);
        }

        $objectives = json_decode((string) file_get_contents($this->runDirectory.'/objectives.json'), true, 512, JSON_THROW_ON_ERROR);
        $presentation = (new FinalistStabilityPresentationBuilder())->build($result, $plan, $objectives, str_repeat('a', 64));
        self::assertSame(FinalistStabilityPresentationBuilder::SCHEMA_VERSION, $presentation['schemaVersion']);
        self::assertCount(3, $presentation['finalists']);
        self::assertCount(2, $presentation['finalists'][0]['batchSummaries']);
        self::assertSame(['retain-candidate', 'request-new-experiment'], $presentation['decision']['options']);
        $resources = dirname(__DIR__).'/resources';
        $html = (new AcceptanceOverlayRenderer())->finalistComparisonHtml(
            $presentation,
            (string) file_get_contents($resources.'/finalist-stability.html'),
            (string) file_get_contents($resources.'/vendor/echarts-5.6.0.min.js'),
            (string) file_get_contents($resources.'/finalist-stability-model.js'),
            (string) file_get_contents($resources.'/finalist-stability-app.js'),
        );
        self::assertStringContainsString('Variation entre lots', $html);
        self::assertStringContainsString('Retenir ce candidat', $html);
        self::assertStringNotContainsString('__COMPARISON_JSON__', $html);
    }

    public function testFrozenCandidateMutationIsRejectedBeforeRunning(): void
    {
        $temporary = sys_get_temp_dir().'/waar-t33-freeze-'.bin2hex(random_bytes(6));
        $this->copyT31Inputs($temporary);
        $plan = (new FinalistStabilityPlanBuilder())->buildFromDirectory($temporary, [104729], 1);
        file_put_contents($temporary.'/'.$plan['candidates'][1]['sourcePath'], "\n", FILE_APPEND);

        try {
            (new FinalistStabilityRunner())->verifyFrozenInputs($plan, $temporary);
            self::fail('Candidate mutation should have been rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('changed after planning', $exception->getMessage());
        } finally {
            $this->removeDirectory($temporary);
        }
    }

    public function testRejectsDivergentT31SeedAndIterationCountBeforePlanning(): void
    {
        foreach ([['combatBaseSeed', 43], ['iterationsPerScenario', 201]] as [$field, $value]) {
            $temporary = sys_get_temp_dir().'/waar-t33-sampling-'.bin2hex(random_bytes(6));
            $this->copyT31Inputs($temporary);
            $searchPlan = json_decode((string) file_get_contents($temporary.'/search-plan.json'), true, 512, JSON_THROW_ON_ERROR);
            $searchPlan['sampling'][$field] = $value;
            file_put_contents($temporary.'/search-plan.json', json_encode($searchPlan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            try {
                (new FinalistStabilityPlanBuilder())->buildFromDirectory($temporary, [42], 200);
                self::fail(sprintf('Divergent %s should be rejected.', $field));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('sampling differs from the verified experiment', $exception->getMessage());
            } finally {
                $this->removeDirectory($temporary);
            }
        }
    }

    public function testCliRejectsInconsistentT31SamplingWithoutWritingAPlanOrMeasuring(): void
    {
        $temporary = sys_get_temp_dir().'/waar-t33-cli-'.bin2hex(random_bytes(6));
        $source = $temporary.'/source';
        $output = $temporary.'/output';
        $this->copyT31Inputs($source);
        $searchPlan = json_decode((string) file_get_contents($source.'/search-plan.json'), true, 512, JSON_THROW_ON_ERROR);
        $searchPlan['sampling']['combatBaseSeed'] = 43;
        file_put_contents($source.'/search-plan.json', json_encode($searchPlan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $command = [PHP_BINARY, dirname(__DIR__).'/bin/validate-finalist-stability.php', $source, $output, '1', '42'];
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        try {
            self::assertSame(1, $exitCode, $stdout."\n".$stderr);
            self::assertStringContainsString('sampling differs from the verified experiment', $stderr);
            self::assertFileDoesNotExist($output.'/validation-plan.json');
            self::assertFileDoesNotExist($output.'/result.json');
        } finally {
            $this->removeDirectory($temporary);
        }
    }

    public function testRejectsMismatchedT31PlanIdentityInputHashAndCacheContract(): void
    {
        $mutations = [
            [static function (array &$plan): void {
                $plan['id'] = 'different-plan';
            }, 'plan id differs'],
            [static function (array &$plan): void {
                $plan['inputs']['sha256']['experiment'] = str_repeat('0', 64);
            }, 'disagree on the experiment SHA-256'],
            [static function (array &$plan): void {
                $plan['baselineCache']['keySha256'] = str_repeat('0', 64);
            }, 'baseline cache contract differs'],
        ];
        foreach ($mutations as [$mutate, $message]) {
            $temporary = sys_get_temp_dir().'/waar-t33-contract-'.bin2hex(random_bytes(6));
            $this->copyT31Inputs($temporary);
            $searchPlan = json_decode((string) file_get_contents($temporary.'/search-plan.json'), true, 512, JSON_THROW_ON_ERROR);
            $mutate($searchPlan);
            file_put_contents($temporary.'/search-plan.json', json_encode($searchPlan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            try {
                (new FinalistStabilityPlanBuilder())->buildFromDirectory($temporary, [104729], 1);
                self::fail('Inconsistent T31 contract should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            } finally {
                $this->removeDirectory($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    private function evaluation(bool $strict, string $state, int $draws): array
    {
        $entries = [];
        for ($index = 1; $index <= 32; ++$index) {
            $entries[] = ['objectiveId' => 'objective-'.$index, 'state' => $state, 'observed' => ['x' => $index / 100, 'y' => $index / 200]];
        }

        return ['strictControls' => ['passed' => $strict], 'draws' => ['count' => $draws], 'entries' => $entries];
    }

    private function copyT31Inputs(string $destination): void
    {
        mkdir($destination.'/finalists', 0777, true);
        foreach (['experiment.json', 'objectives.json', 'search-space.json', 'search-plan.json', 'search-result.json', 'candidate-initial.json'] as $name) {
            copy($this->runDirectory.'/'.$name, $destination.'/'.$name);
        }
        $result = json_decode((string) file_get_contents($destination.'/search-result.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($result['finalists'] as $finalist) {
            $path = $finalist['artifacts']['variant'];
            if (!is_dir($destination.'/'.dirname($path))) {
                mkdir($destination.'/'.dirname($path), 0777, true);
            }
            copy($this->runDirectory.'/'.$path, $destination.'/'.$path);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
