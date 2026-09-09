<?php

namespace Waar\MicroCombat\Experiment;

final readonly class FinalistStabilityRunner
{
    public const SCHEMA_VERSION = 'waar-monotype-finalist-stability-result/0.1';

    public function __construct(
        private ExperimentRunner $runner = new ExperimentRunner(),
        private CanonicalMonotypeSearchObjectiveEvaluator $evaluator = new CanonicalMonotypeSearchObjectiveEvaluator(),
        private ExperimentReportAggregator $aggregator = new ExperimentReportAggregator(),
        private FinalistStabilityAnalyzer $analyzer = new FinalistStabilityAnalyzer(),
    ) {
    }

    /**
     * @param array<string, mixed> $plan
     * @param null|callable(string, int, ?int, int): void $progress Receives event, batch, candidate offset and candidate count.
     * @return array<string, mixed>
     */
    public function run(array $plan, string $t31Directory, ?callable $progress = null): array
    {
        if (FinalistStabilityPlanBuilder::SCHEMA_VERSION !== ($plan['schemaVersion'] ?? null)
            || 'planned-before-measurement' !== ($plan['state'] ?? null)
            || true !== ($plan['seedSeparation']['checkedBeforeMeasurement'] ?? null)
            || 0 !== ($plan['seedSeparation']['collisionsAfterCorrection'] ?? null)) {
            throw new \InvalidArgumentException('T33 requires a valid collision-free plan written before measurement.');
        }
        $t31Directory = rtrim($t31Directory, '/\\');
        $this->verifyFrozenInputs($plan, $t31Directory);
        $reference = ExperimentDefinition::fromFile($t31Directory.'/experiment.json');
        $objectives = $this->readJson($t31Directory.'/objectives.json');
        $candidatePlans = $plan['candidates'] ?? null;
        $baseSeeds = $plan['sampling']['baseSeeds'] ?? null;
        $iterations = $plan['sampling']['iterationsPerScenarioAndCandidate'] ?? null;
        if (!is_array($candidatePlans) || !array_is_list($candidatePlans) || 4 !== count($candidatePlans)
            || !is_array($baseSeeds) || !array_is_list($baseSeeds) || !is_int($iterations)) {
            throw new \InvalidArgumentException('T33 plan candidates or sampling contract is invalid.');
        }

        $variants = [];
        foreach ($candidatePlans as $candidatePlan) {
            $variant = $this->readJson($t31Directory.'/'.$candidatePlan['sourcePath']);
            if (($variant['id'] ?? null) !== ($candidatePlan['id'] ?? null)) {
                throw new \InvalidArgumentException('A frozen candidate id no longer matches its plan.');
            }
            $variants[] = ExperimentVariant::fromArray($variant);
        }

        $candidateBatches = array_fill(0, count($variants), []);
        foreach ($baseSeeds as $batchIndex => $baseSeed) {
            if (!is_int($baseSeed)) {
                throw new \InvalidArgumentException('Every batch base seed must be an integer.');
            }
            if (null !== $progress) {
                $progress('batch-start', $batchIndex + 1, null, count($variants));
            }
            $baselineReport = null;
            foreach ($variants as $candidateIndex => $variant) {
                $experiment = new ExperimentDefinition(
                    $reference->id,
                    $reference->label,
                    $iterations,
                    $baseSeed,
                    $reference->baseline,
                    $variant,
                    $reference->scenarios,
                );
                $report = 0 === $candidateIndex
                    ? $this->runner->run($experiment)
                    : $this->runner->runWithBaselineReport($experiment, $baselineReport);
                $baselineReport ??= $report;
                $evaluation = $this->evaluator->evaluate($report, $objectives);
                $candidateBatches[$candidateIndex][] = [
                    'batch' => $batchIndex + 1,
                    'baseSeed' => $baseSeed,
                    'report' => $report,
                    'evaluation' => $evaluation,
                ];
                if (null !== $progress) {
                    $progress('candidate-complete', $batchIndex + 1, $candidateIndex + 1, count($variants));
                }
            }
            if (null !== $progress) {
                $progress('batch-complete', $batchIndex + 1, null, count($variants));
            }
        }

        $candidates = [];
        foreach ($candidatePlans as $candidateIndex => $candidatePlan) {
            $reports = array_column($candidateBatches[$candidateIndex], 'report');
            $evaluations = array_column($candidateBatches[$candidateIndex], 'evaluation');
            $aggregateReport = $this->aggregator->aggregate($reports);
            $aggregateEvaluation = $this->evaluator->evaluate($aggregateReport, $objectives);
            $analysis = $this->analyzer->analyze($evaluations, $aggregateEvaluation);
            $candidates[] = $candidatePlan + [
                'status' => $analysis['status'],
                'batches' => $candidateBatches[$candidateIndex],
                'aggregate' => [
                    'report' => $aggregateReport,
                    'evaluation' => $aggregateEvaluation,
                ],
                'variationBetweenBatches' => $analysis['variationBetweenBatches'],
            ];
        }
        $this->verifyFrozenInputs($plan, $t31Directory);

        $scenarioCount = count($reference->scenarios);
        $batchCount = count($baseSeeds);
        $candidateCount = count($variants);
        $candidateCombats = $scenarioCount * $iterations * $batchCount * $candidateCount;
        $baselineCombats = $scenarioCount * $iterations * $batchCount;
        $logicalCombats = $scenarioCount * $iterations * $batchCount * $candidateCount * 2;
        $statuses = array_count_values(array_column($candidates, 'status'));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'planId' => $plan['id'],
            'state' => 'completed',
            'measurement' => [
                'batchCount' => $batchCount,
                'iterationsPerScenarioAndCandidate' => $iterations,
                'scenarioCount' => $scenarioCount,
                'observationCountPerEvaluation' => 2 * $scenarioCount,
                'baseSeeds' => $baseSeeds,
                'pairedSeeds' => true,
            ],
            'execution' => [
                'candidateCombatCount' => $candidateCombats,
                'baselineCombatCount' => $baselineCombats,
                'baselineCacheHits' => $batchCount * ($candidateCount - 1),
                'actualCombatCount' => $candidateCombats + $baselineCombats,
                'logicalReportCombatCount' => $logicalCombats,
            ],
            'inputIntegrity' => [
                'verifiedBeforeMeasurement' => true,
                'verifiedAfterMeasurement' => true,
                'candidateParametersUnchanged' => true,
                'objectivesUnchanged' => true,
                'experimentUnchanged' => true,
            ],
            'order' => [
                'source' => 'T31 finalist rank',
                'preserved' => true,
                'opportunisticRankingPerformed' => false,
            ],
            'candidates' => $candidates,
            'summary' => [
                'candidateCount' => count($candidates),
                'statusCounts' => $statuses,
                'stableCandidateCount' => $statuses['stable-sur-les-lots'] ?? 0,
                'poDecisionOptions' => ['retain-candidate', 'request-new-experiment'],
            ],
            'limitations' => [
                'Statuses describe only the five reserved batches and their pooled aggregate.',
                'Variation between batches is an observed range, not a confidence interval.',
                'A search inspired by these results requires a new experiment and newly reserved validation seeds.',
            ],
        ];
    }

    /** @param array<string, mixed> $plan */
    public function verifyFrozenInputs(array $plan, string $t31Directory): void
    {
        $expected = $plan['inputs']['sha256'] ?? [];
        $paths = [
            'experiment' => 'experiment.json',
            'objectives' => 'objectives.json',
            'searchPlan' => 'search-plan.json',
            'searchResult' => 'search-result.json',
        ];
        foreach ($paths as $key => $path) {
            $actual = hash_file('sha256', rtrim($t31Directory, '/\\').'/'.$path);
            if (!is_string($expected[$key] ?? null) || !hash_equals($expected[$key], $actual)) {
                throw new \RuntimeException(sprintf('Frozen T33 input "%s" changed after planning.', $key));
            }
        }
        foreach ($plan['candidates'] ?? [] as $candidate) {
            $actual = hash_file('sha256', rtrim($t31Directory, '/\\').'/'.$candidate['sourcePath']);
            if (!is_string($candidate['sha256'] ?? null) || !hash_equals($candidate['sha256'], $actual)) {
                throw new \RuntimeException(sprintf('Frozen candidate "%s" changed after planning.', $candidate['id'] ?? '?'));
            }
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException(sprintf('JSON root in "%s" must be an object.', $path));
        }

        return $decoded;
    }
}
