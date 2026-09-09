<?php

namespace Waar\MicroCombat\Experiment;

final readonly class MixedCompositionObservationRunner
{
    public const SCHEMA_VERSION = 'waar-mixed-composition-observation-result/0.1';

    public function __construct(private ExperimentRunner $runner = new ExperimentRunner()) {}

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function run(array $plan, string $inputDirectory, ?callable $progress = null): array
    {
        $this->verifyInputs($plan, $inputDirectory);
        $this->expect(MixedCompositionObservationPlanBuilder::SCHEMA_VERSION === ($plan['schemaVersion'] ?? null), 'Unsupported T34 plan.');
        $scenariosSource = ExperimentDefinition::fromFile(rtrim($inputDirectory, '/\\').'/t24-corpus.json');
        $iterations = $plan['sampling']['iterationsPerScenarioAndVariant'] ?? null;
        $baseSeed = $plan['sampling']['baseSeed'] ?? null;
        $variantPlans = $plan['variants'] ?? null;
        $this->expect(is_int($iterations) && is_int($baseSeed) && is_array($variantPlans) && 4 === count($variantPlans), 'Invalid T34 plan contents.');

        $variants = [];
        foreach ($variantPlans as $index => $candidate) {
            $variant = ExperimentVariant::fromArray($this->readJson(sprintf('%s/candidate-%02d.json', rtrim($inputDirectory, '/\\'), $index)));
            $this->expect($variant->id === ($candidate['id'] ?? null), 'A T34 candidate id no longer matches its plan.');
            $variants[] = $variant;
        }

        $reports = [];
        $baselineReport = null;
        foreach (array_slice($variants, 1, null, true) as $index => $variant) {
            $experiment = new ExperimentDefinition(
                't34-mixed-composition-observation',
                'T34 — observation des compositions mixtes T24',
                $iterations,
                $baseSeed,
                $variants[0],
                $variant,
                $scenariosSource->scenarios,
            );
            $reports[$index] = null === $baselineReport ? $this->runner->runDetailed($experiment) : $this->runner->runDetailedWithBaselineReport($experiment, $baselineReport);
            $baselineReport ??= $reports[$index];
            if (null !== $progress) {
                $progress($index, count($variants) - 1);
            }
        }
        $this->verifyInputs($plan, $inputDirectory);

        $initialRows = array_map(fn (array $row): array => $this->sampleRow($row, $row['baseline']), $baselineReport['rows']);
        $initial = $this->candidate($variantPlans[0], $initialRows);
        $finalists = [];
        foreach ($reports as $index => $report) {
            $rows = [];
            foreach ($report['rows'] as $offset => $row) {
                $candidateRow = $this->sampleRow($row, $row['candidate']);
                $candidateRow['changeFromInitial'] = $this->change($initialRows[$offset], $candidateRow);
                $rows[] = $candidateRow;
            }
            $finalists[] = $this->candidate($variantPlans[$index], $rows);
        }

        $majorities = $this->majorityObservations($initial, $finalists, $scenariosSource->scenarios);
        $scenarioCount = count($scenariosSource->scenarios);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'planId' => $plan['id'],
            'state' => 'completed',
            'measurement' => [
                'iterationsPerScenarioAndVariant' => $iterations,
                'baseSeed' => $baseSeed,
                'scenarioCount' => $scenarioCount,
                'variantCount' => count($variants),
                'pairedSeeds' => true,
            ],
            'execution' => [
                'initialCombatCount' => $scenarioCount * $iterations,
                'finalistCombatCount' => $scenarioCount * $iterations * count($finalists),
                'actualCombatCount' => $scenarioCount * $iterations * count($variants),
                'logicalComparisonCombatCount' => $scenarioCount * $iterations * count($finalists) * 2,
                'initialCacheHits' => count($finalists) - 1,
            ],
            'inputIntegrity' => [
                'verifiedBeforeMeasurement' => true,
                'verifiedAfterMeasurement' => true,
                'scenarioIdsCompositionsAndBudgetsUnchanged' => true,
                'candidateParametersUnchanged' => true,
            ],
            'initial' => $initial,
            'finalists' => $finalists,
            'observations' => $majorities + ['largestAbsoluteGaps' => $this->largestGaps($initialRows, $finalists)],
            'interpretation' => [
                'descriptiveOnly' => true,
                'acceptanceDecision' => null,
                'legacyFidelityVerdict' => null,
                'selectionPerformed' => false,
                't33StatusesPreserved' => true,
                'monotypeFailureStillApplies' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $candidate @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function candidate(array $candidate, array $rows): array
    {
        return [
            'kind' => $candidate['kind'],
            't31Order' => $candidate['t31Order'],
            'id' => $candidate['id'],
            'version' => $candidate['version'],
            'parameterFingerprint' => $candidate['parameterFingerprint'],
            't33Status' => $candidate['t33Status'],
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $sample @return array<string, mixed> */
    private function sampleRow(array $source, array $sample): array
    {
        return [
            'scenarioId' => $source['scenarioId'],
            'scenarioLabel' => $source['scenarioLabel'],
            'side' => $source['side'],
            'focus' => $source['focus'],
            'army' => $source['army'],
            'wins' => $sample['wins'],
            'losses' => $sample['iterations'] - $sample['wins'] - $sample['draws'],
            'draws' => $sample['draws'],
            'iterations' => $sample['iterations'],
            'winRate' => $sample['winRate']['value'],
            'meanRounds' => $sample['meanRounds'],
            'metrics' => array_map(static fn (array $metric): float => $metric['value'], $sample['metrics']),
            'units' => $sample['units'],
        ];
    }

    /** @param array<string, mixed> $from @param array<string, mixed> $to @return array<string, mixed> */
    private function change(array $from, array $to): array
    {
        $metricChanges = [];
        foreach (['survivors', 'structure', 'economicValue'] as $metric) {
            $metricChanges[$metric] = ['from' => $from['metrics'][$metric], 'to' => $to['metrics'][$metric], 'delta' => $to['metrics'][$metric] - $from['metrics'][$metric]];
        }
        $units = [];
        foreach ($from['units'] as $type => $unit) {
            $units[$type] = [
                'meanSurvivors' => ['from' => $unit['meanSurvivors'], 'to' => $to['units'][$type]['meanSurvivors'], 'delta' => $to['units'][$type]['meanSurvivors'] - $unit['meanSurvivors']],
                'meanLosses' => ['from' => $unit['meanLosses'], 'to' => $to['units'][$type]['meanLosses'], 'delta' => $to['units'][$type]['meanLosses'] - $unit['meanLosses']],
            ];
        }

        return [
            'winRate' => ['from' => $from['winRate'], 'to' => $to['winRate'], 'delta' => $to['winRate'] - $from['winRate']],
            'meanRounds' => ['from' => $from['meanRounds'], 'to' => $to['meanRounds'], 'delta' => $to['meanRounds'] - $from['meanRounds']],
            'metrics' => $metricChanges,
            'units' => $units,
        ];
    }

    /** @param array<string, mixed> $initial @param list<array<string, mixed>> $finalists @param list<ExperimentScenario> $scenarios @return array<string, mixed> */
    private function majorityObservations(array $initial, array $finalists, array $scenarios): array
    {
        $majority = static function (array $rows, string $scenarioId): string {
            $bySide = [];
            foreach ($rows as $row) {
                if ($scenarioId === $row['scenarioId']) {
                    $bySide[$row['side']] = $row;
                }
            }
            if ($bySide['attacker']['wins'] > $bySide['defender']['wins']) {
                return 'attacker';
            }
            if ($bySide['defender']['wins'] > $bySide['attacker']['wins']) {
                return 'defender';
            }

            return 'none';
        };
        $reversals = [];
        $byVariant = [];
        foreach ([$initial, ...$finalists] as $candidate) {
            foreach ($scenarios as $scenario) {
                $winner = $majority($candidate['rows'], $scenario->id);
                $byVariant[$candidate['id']][$scenario->id] = $winner;
                if ('initial' !== $candidate['kind']) {
                    $from = $majority($initial['rows'], $scenario->id);
                    if ($winner !== $from) {
                        $reversals[] = ['candidateId' => $candidate['id'], 'scenarioId' => $scenario->id, 'scenarioLabel' => $scenario->label, 'from' => $from, 'to' => $winner];
                    }
                }
            }
        }

        return ['majorityWinners' => $byVariant, 'majorityWinnerReversals' => $reversals];
    }

    /** @param list<array<string, mixed>> $initialRows @param list<array<string, mixed>> $finalists @return list<array<string, mixed>> */
    private function largestGaps(array $initialRows, array $finalists): array
    {
        $largest = [];
        foreach ($finalists as $candidate) {
            foreach ($candidate['rows'] as $offset => $row) {
                foreach (['winRate' => $row['changeFromInitial']['winRate'], 'survivors' => $row['changeFromInitial']['metrics']['survivors'], 'structure' => $row['changeFromInitial']['metrics']['structure'], 'economicValue' => $row['changeFromInitial']['metrics']['economicValue'], 'meanRounds' => $row['changeFromInitial']['meanRounds']] as $metric => $change) {
                    if (!isset($largest[$metric]) || abs($change['delta']) > abs($largest[$metric]['delta'])) {
                        $largest[$metric] = [
                            'metric' => $metric,
                            'candidateId' => $candidate['id'],
                            'scenarioId' => $row['scenarioId'],
                            'scenarioLabel' => $row['scenarioLabel'],
                            'side' => $row['side'],
                        ] + $change;
                    }
                }
            }
        }

        return array_values($largest);
    }

    /** @param array<string, mixed> $plan */
    public function verifyInputs(array $plan, string $inputDirectory): void
    {
        $directory = rtrim($inputDirectory, '/\\');
        $expectedCorpus = $plan['corpus']['sha256'] ?? null;
        $actualCorpus = hash_file('sha256', $directory.'/t24-corpus.json');
        $this->expect(is_string($expectedCorpus) && is_string($actualCorpus) && hash_equals($expectedCorpus, $actualCorpus), 'Frozen T24 corpus changed after planning.');
        foreach ($plan['variants'] ?? [] as $index => $candidate) {
            $actual = hash_file('sha256', sprintf('%s/candidate-%02d.json', $directory, $index));
            $this->expect(is_string($candidate['sha256'] ?? null) && is_string($actual) && hash_equals($candidate['sha256'], $actual), 'A frozen T34 candidate changed after planning.');
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException(sprintf('JSON root in "%s" must be an object.', $path));
        }

        return $decoded;
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
