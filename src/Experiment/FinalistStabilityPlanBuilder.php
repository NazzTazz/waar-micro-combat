<?php

namespace Waar\MicroCombat\Experiment;

final class FinalistStabilityPlanBuilder
{
    public const SCHEMA_VERSION = 'waar-monotype-finalist-stability-plan/0.1';

    /**
     * @param list<int> $requestedBaseSeeds
     * @return array<string, mixed>
     */
    public function buildFromDirectory(string $t31Directory, array $requestedBaseSeeds, int $iterations = 1000): array
    {
        $t31Directory = rtrim($t31Directory, '/\\');
        if ([] === $requestedBaseSeeds || !array_is_list($requestedBaseSeeds) || $iterations < 1 || $iterations > 100_000) {
            throw new \InvalidArgumentException('Invalid validation sampling plan.');
        }
        $result = $this->readJson($t31Directory.'/search-result.json');
        $searchPlan = $this->readJson($t31Directory.'/search-plan.json');
        $experiment = $this->readJson($t31Directory.'/experiment.json');
        $verifiedExperiment = ExperimentDefinition::fromFile($t31Directory.'/experiment.json');
        $objectives = $this->readJson($t31Directory.'/objectives.json');
        $initial = $this->readJson($t31Directory.'/candidate-initial.json');

        $this->expect('waar-monotype-search-result/0.1' === ($result['schemaVersion'] ?? null), 'Unsupported T31 search result.');
        $this->expect('completed' === ($result['state'] ?? null), 'T33 requires a completed T31 search.');
        $this->expect('waar-monotype-search-plan/0.1' === ($searchPlan['schemaVersion'] ?? null), 'Unsupported T31 search plan.');
        $this->expect(($searchPlan['id'] ?? null) === ($result['planId'] ?? null), 'The T31 search plan id differs from the completed result.');
        $this->expect(32 === count($objectives['zones'] ?? []), 'T33 requires the 32 frozen objectives.');
        $this->expect(($experiment['candidate'] ?? null) === $initial, 'The T31 initial candidate and experiment differ.');
        $inputFiles = [
            'experiment' => ['path' => 'experiment.json', 'label' => 'experiment'],
            'objectives' => ['path' => 'objectives.json', 'label' => 'objectives'],
            'candidateInitial' => ['path' => 'candidate-initial.json', 'label' => 'initial candidate'],
        ];
        foreach ($inputFiles as $key => $input) {
            $resultHash = $result['inputSha256'][$key] ?? null;
            $planHash = $searchPlan['inputs']['sha256'][$key] ?? null;
            $this->expect(is_string($resultHash) && is_string($planHash) && hash_equals(strtolower($resultHash), strtolower($planHash)), sprintf('The T31 plan and result disagree on the %s SHA-256.', $input['label']));
            $this->verifyHash($t31Directory.'/'.$input['path'], $resultHash, $input['label']);
        }
        $this->expect(is_string($result['inputSha256']['searchSpace'] ?? null)
            && hash_equals(strtolower($result['inputSha256']['searchSpace']), strtolower((string) ($searchPlan['inputs']['sha256']['searchSpace'] ?? ''))), 'The T31 plan and result disagree on the search space SHA-256.');
        $this->expect(($searchPlan['inputs']['experimentId'] ?? null) === ($experiment['id'] ?? null), 'The T31 plan and experiment ids differ.');
        $this->expect(($searchPlan['inputs']['objectiveGenerationId'] ?? null) === ($objectives['generation']['id'] ?? null), 'The T31 plan and objective generation ids differ.');
        $this->expect(is_string($searchPlan['inputs']['searchSpaceId'] ?? null)
            && '' !== trim($searchPlan['inputs']['searchSpaceId'])
            && is_string($searchPlan['inputs']['searchSpaceVersion'] ?? null)
            && '' !== trim($searchPlan['inputs']['searchSpaceVersion']), 'The T31 search-space identity is missing.');
        $this->expect(($searchPlan['limits']['uniqueEvaluationBudget'] ?? null) === ($result['counts']['evaluationBudget'] ?? null)
            && ($searchPlan['limits']['proposalLimit'] ?? null) === ($result['counts']['proposalLimit'] ?? null), 'The T31 plan and result budgets differ.');
        $this->expect(($result['counts']['evaluatedCandidates'] ?? null) === ($result['counts']['evaluationBudget'] ?? null), 'T33 requires the completed T31 evaluation budget.');

        $candidates = [[
            'kind' => 'initial',
            'rank' => null,
            'id' => $initial['id'],
            'version' => $initial['version'],
            'parameterFingerprint' => $result['initial']['parameterFingerprint'] ?? null,
            'sourcePath' => 'candidate-initial.json',
            'sha256' => hash_file('sha256', $t31Directory.'/candidate-initial.json'),
        ]];
        $expectedRank = 1;
        foreach ($result['finalists'] ?? [] as $finalist) {
            $rank = $finalist['rank'] ?? null;
            $path = $finalist['artifacts']['variant'] ?? null;
            $this->expect($expectedRank === $rank && is_string($path) && !str_contains(str_replace('\\', '/', $path), '../'), 'T31 finalist order or path is invalid.');
            $this->verifyHash($t31Directory.'/'.$path, $finalist['sha256']['variant'] ?? null, sprintf('finalist %d', $rank));
            $variant = $this->readJson($t31Directory.'/'.$path);
            $this->expect(($finalist['candidateId'] ?? null) === ($variant['id'] ?? null), 'A finalist id differs from its frozen variant.');
            $candidates[] = [
                'kind' => 'finalist',
                'rank' => $rank,
                'id' => $variant['id'],
                'version' => $variant['version'],
                'parameterFingerprint' => $finalist['parameterFingerprint'] ?? null,
                'sourcePath' => str_replace('\\', '/', $path),
                'sha256' => hash_file('sha256', $t31Directory.'/'.$path),
            ];
            ++$expectedRank;
        }
        $this->expect(4 === count($candidates), 'T33 requires the initial candidate and the three ordered T31 finalists.');

        $scenarioIds = array_map(static fn (array $scenario): mixed => $scenario['id'] ?? null, $experiment['scenarios'] ?? []);
        $this->expect(16 === count($scenarioIds) && 16 === count(array_unique($scenarioIds)) && !in_array(null, $scenarioIds, true), 'T33 requires the ordered 16-scenario corpus.');
        $searchBaseSeed = $verifiedExperiment->baseSeed;
        $searchIterations = $verifiedExperiment->iterations;
        $this->expect($searchBaseSeed === ($searchPlan['sampling']['combatBaseSeed'] ?? null)
            && $searchIterations === ($searchPlan['sampling']['iterationsPerScenario'] ?? null)
            && true === ($searchPlan['sampling']['pairedCombatSeeds'] ?? null), 'The T31 search plan sampling differs from the verified experiment.');
        $expectedBaselineCacheKey = hash('sha256', $this->compact([
            'variant' => $verifiedExperiment->baseline->toArray(),
            'iterations' => $searchIterations,
            'baseSeed' => $searchBaseSeed,
            'scenarios' => array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $verifiedExperiment->scenarios),
        ]));
        $this->expect(true === ($searchPlan['baselineCache']['enabled'] ?? null)
            && hash_equals($expectedBaselineCacheKey, (string) ($searchPlan['baselineCache']['keySha256'] ?? '')), 'The T31 baseline cache contract differs from the verified experiment.');
        $separation = $this->separateSeeds($scenarioIds, $searchBaseSeed, $searchIterations, $requestedBaseSeeds, $iterations);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'id' => 't33-finalist-stability-'.$result['planId'],
            'slice' => 'T33',
            'state' => 'planned-before-measurement',
            'measurementStarted' => false,
            'sourceRun' => [
                'planId' => $result['planId'],
                'runId' => basename(str_replace('\\', '/', $t31Directory)),
                'finalistOrderPreserved' => true,
                'parameterMutationAllowed' => false,
            ],
            'inputs' => [
                'experimentId' => $experiment['id'] ?? null,
                'objectiveGenerationId' => $objectives['generation']['id'] ?? null,
                'sha256' => [
                    'experiment' => hash_file('sha256', $t31Directory.'/experiment.json'),
                    'corpus' => hash('sha256', $this->compact($experiment['scenarios'])),
                    'objectives' => hash_file('sha256', $t31Directory.'/objectives.json'),
                    'searchPlan' => hash_file('sha256', $t31Directory.'/search-plan.json'),
                    'searchResult' => hash_file('sha256', $t31Directory.'/search-result.json'),
                ],
            ],
            'candidates' => $candidates,
            'sampling' => [
                'batchCount' => count($separation['effectiveBaseSeeds']),
                'iterationsPerScenarioAndCandidate' => $iterations,
                'requestedBaseSeeds' => $requestedBaseSeeds,
                'baseSeeds' => $separation['effectiveBaseSeeds'],
                'pairedBetweenInitialBaselineAndFinalists' => true,
                'derivation' => 'ExperimentRunner::deriveSeed(baseSeed, scenarioId, iteration)',
            ],
            'seedSeparation' => $separation['audit'],
            'aggregation' => [
                'method' => 'sum exact counters, numerators and denominators; then re-evaluate all ellipses',
                'forbidden' => 'averaging batch losses or binary objective states',
            ],
            'statusDefinitions' => [
                'stable-sur-les-lots' => '32/32 objectives and zero draws in every batch and in the pooled aggregate',
                'variable-selon-le-lot' => 'at least one objective changes state between batches',
                'objectifs-non-atteints' => 'strict failure without an objective state change between batches',
                'invariant-nuls-en-echec' => 'at least one draw; highest priority',
            ],
        ];
    }

    /**
     * @param list<string> $scenarioIds
     * @param list<int> $requestedBaseSeeds
     * @return array{effectiveBaseSeeds: list<int>, audit: array<string, mixed>}
     */
    public function separateSeeds(array $scenarioIds, int $searchBaseSeed, int $searchIterations, array $requestedBaseSeeds, int $validationIterations): array
    {
        if ([] === $scenarioIds || [] === $requestedBaseSeeds || $searchIterations < 1 || $validationIterations < 1) {
            throw new \InvalidArgumentException('Seed separation inputs are invalid.');
        }
        $searchSets = [];
        foreach ($scenarioIds as $scenarioId) {
            $searchSets[$scenarioId] = $this->derivedSet($searchBaseSeed, $scenarioId, $searchIterations);
        }

        $usedByScenario = array_fill_keys($scenarioIds, []);
        $effective = [];
        $corrections = [];
        $batchAudits = [];
        foreach ($requestedBaseSeeds as $batchIndex => $requested) {
            if (!is_int($requested) || $requested < 0 || $requested > 2_147_483_647) {
                throw new \InvalidArgumentException('Base seeds must be 31-bit non-negative integers.');
            }
            $candidate = $requested;
            $attempts = 0;
            while (true) {
                ++$attempts;
                if ($attempts > 100_000 || $candidate > 2_147_483_647) {
                    throw new \RuntimeException(sprintf('Unable to reserve a collision-free seed for batch %d.', $batchIndex + 1));
                }
                $sets = [];
                $reason = null;
                foreach ($scenarioIds as $scenarioId) {
                    $set = $this->derivedSet($candidate, $scenarioId, $validationIterations);
                    if (count($set) !== $validationIterations) {
                        $reason = 'duplicate-derived-seed-within-batch';
                        break;
                    }
                    if ([] !== array_intersect_key($set, $searchSets[$scenarioId])) {
                        $reason = 'collision-with-t31-search';
                        break;
                    }
                    if ([] !== array_intersect_key($set, $usedByScenario[$scenarioId])) {
                        $reason = 'collision-with-another-validation-batch';
                        break;
                    }
                    $sets[$scenarioId] = $set;
                }
                if (null === $reason) {
                    break;
                }
                $corrections[] = ['batch' => $batchIndex + 1, 'rejectedBaseSeed' => $candidate, 'reason' => $reason];
                ++$candidate;
            }
            $effective[] = $candidate;
            $fingerprintValues = [];
            foreach ($sets as $scenarioId => $set) {
                $usedByScenario[$scenarioId] += $set;
                $fingerprintValues[$scenarioId] = array_map('intval', array_keys($set));
            }
            $batchAudits[] = [
                'batch' => $batchIndex + 1,
                'requestedBaseSeed' => $requested,
                'baseSeed' => $candidate,
                'corrected' => $candidate !== $requested,
                'derivedSeedCount' => count($scenarioIds) * $validationIterations,
                'derivedSeedsSha256' => hash('sha256', $this->compact($fingerprintValues)),
            ];
        }

        return [
            'effectiveBaseSeeds' => $effective,
            'audit' => [
                'checkedBeforeMeasurement' => true,
                'sameScenarioComparison' => true,
                'searchBaseSeed' => $searchBaseSeed,
                'searchIterationsPerScenario' => $searchIterations,
                'searchDerivedSeedCount' => count($scenarioIds) * $searchIterations,
                'validationDerivedSeedCount' => count($scenarioIds) * $validationIterations * count($effective),
                'collisionsAfterCorrection' => 0,
                'corrections' => $corrections,
                'batches' => $batchAudits,
            ],
        ];
    }

    /** @return array<string, true> */
    private function derivedSet(int $baseSeed, string $scenarioId, int $iterations): array
    {
        $set = [];
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $set[(string) ExperimentRunner::deriveSeed($baseSeed, $scenarioId, $iteration)] = true;
        }

        return $set;
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

    private function verifyHash(string $path, mixed $expected, string $label): void
    {
        $this->expect(is_string($expected) && hash_equals(strtolower($expected), hash_file('sha256', $path)), sprintf('The frozen %s SHA-256 differs from T31.', $label));
    }

    private function compact(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
