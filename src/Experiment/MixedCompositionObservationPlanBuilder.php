<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\CombatTieBreakPolicy;

final class MixedCompositionObservationPlanBuilder
{
    public const SCHEMA_VERSION = 'waar-mixed-composition-observation-plan/0.1';
    public const T24_SHA256 = '551b41923a588e01cd3cb5a2a38d822e4e01a231ca2818f90b5dc7e623e761fb';
    public const BASE_SEED = 32_452_843;
    public const ITERATIONS = 1_000;

    /** @return array<string, mixed> */
    public function build(string $t24Path, string $t33Directory, int $iterations = self::ITERATIONS, int $baseSeed = self::BASE_SEED): array
    {
        if ($iterations < 1 || $iterations > 100_000 || $baseSeed < 0 || $baseSeed > 2_147_483_647) {
            throw new \InvalidArgumentException('Invalid T34 sampling contract.');
        }
        $t24Hash = hash_file('sha256', $t24Path);
        $this->expect(is_string($t24Hash) && hash_equals(self::T24_SHA256, $t24Hash), 'The frozen T24 corpus does not match its accepted SHA-256.');
        $t24 = ExperimentDefinition::fromFile($t24Path);
        $this->expect('t24-astra-vector-corrections' === $t24->id && 6 === count($t24->scenarios), 'T34 requires the six accepted T24 scenarios.');
        $this->expect(null === $t24->baseline->declaredTieBreakPolicy && null === $t24->candidate->declaredTieBreakPolicy, 'The historical T24 tie policy must remain implicit draw.');

        $directory = rtrim($t33Directory, '/\\');
        $t33PlanPath = $directory.'/validation-plan.json';
        $t33ResultPath = $directory.'/result.json';
        $t33Plan = $this->readJson($t33PlanPath);
        $t33Result = $this->readJson($t33ResultPath);
        $this->expect('waar-monotype-finalist-stability-plan/0.1' === ($t33Plan['schemaVersion'] ?? null), 'Unsupported T33 plan.');
        $this->expect('waar-monotype-finalist-stability-result/0.1' === ($t33Result['schemaVersion'] ?? null) && 'completed' === ($t33Result['state'] ?? null), 'T33 result must be complete.');
        $this->expect(($t33Plan['id'] ?? null) === ($t33Result['planId'] ?? null), 'T33 plan and result do not match.');
        $this->expect(4 === count($t33Plan['candidates'] ?? []) && 4 === count($t33Result['candidates'] ?? []), 'T34 requires the initial variant and three frozen finalists.');

        $candidates = [];
        foreach ($t33Plan['candidates'] as $index => $candidate) {
            $resultCandidate = $t33Result['candidates'][$index] ?? null;
            $copy = $t33Plan['frozenCopies']['candidate-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)] ?? null;
            $this->expect(is_array($resultCandidate) && is_array($copy), 'T33 candidate provenance is incomplete.');
            $this->expect(($candidate['id'] ?? null) === ($resultCandidate['id'] ?? null), 'T33 candidate order changed.');
            $sourcePath = $directory.'/'.($copy['path'] ?? '');
            $sourceHash = hash_file('sha256', $sourcePath);
            $this->expect(is_string($sourceHash) && is_string($copy['sha256'] ?? null) && hash_equals($copy['sha256'], $sourceHash), 'A frozen T33 candidate changed.');
            $variant = ExperimentVariant::fromArray($this->readJson($sourcePath));
            $this->expect(CombatTieBreakPolicy::Defender === $variant->ruleset->tieBreakPolicy, 'Every T34 variant must use defender tie-break.');
            $variantCosts = array_map(static fn (array $profile): int => $profile['cost'], $variant->catalog->toArray());
            $this->expect(['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350] === $variantCosts, 'T34 unit costs must remain frozen.');
            $candidates[] = [
                'kind' => $candidate['kind'],
                't31Order' => $candidate['rank'],
                'id' => $candidate['id'],
                'version' => $candidate['version'],
                'parameterFingerprint' => $candidate['parameterFingerprint'],
                't33Status' => $resultCandidate['status'],
                'sourcePath' => $sourcePath,
                'sha256' => $sourceHash,
            ];
        }
        $this->expect('initial' === $candidates[0]['kind'] && [1, 2, 3] === array_column(array_slice($candidates, 1), 't31Order'), 'T31 finalist order must be preserved.');

        $costs = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];
        $scenarios = array_map(static function (ExperimentScenario $scenario) use ($costs): array {
            $values = $scenario->toArray();
            $budget = static fn (array $army): int => array_sum(array_map(static fn (string $type, int $count): int => $costs[$type] * $count, array_keys($army), $army));

            return $values + ['budgets' => ['attacker' => $budget($scenario->attacker), 'defender' => $budget($scenario->defender)]];
        }, $t24->scenarios);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'id' => 't34-mixed-composition-observation',
            'slice' => 'T34',
            'state' => 'planned-before-measurement',
            'measurementStarted' => false,
            'purpose' => 'descriptive comparison on the frozen mixed-composition T24 corpus',
            'sampling' => [
                'iterationsPerScenarioAndVariant' => $iterations,
                'baseSeed' => $baseSeed,
                'pairedSeeds' => true,
                'derivation' => 'ExperimentRunner::deriveSeed(baseSeed, scenarioId, iteration)',
                'reservedForT34' => true,
            ],
            'corpus' => [
                'id' => $t24->id,
                'sourcePath' => $t24Path,
                'sha256' => $t24Hash,
                'scenarioCount' => count($scenarios),
                'exactIdsCompositionsAndBudgetsPreserved' => true,
                'scenarios' => $scenarios,
            ],
            'variants' => $candidates,
            'combatPolicy' => [
                'tieBreakPolicy' => 'defender',
                'sameForInitialAndFinalists' => true,
                'costs' => $costs,
                'parameterMutationAllowed' => false,
            ],
            'historicalReference' => [
                'id' => $t24->id,
                'iterations' => $t24->iterations,
                'baseSeed' => $t24->baseSeed,
                'tieBreakPolicy' => 'draw',
                'policyProvenance' => 'implicit default because tieBreakPolicy is absent from both historical variants',
                'displayedAsMeasurement' => false,
                'parametersAttributionAllowed' => false,
            ],
            't33' => [
                'planId' => $t33Plan['id'],
                'acceptedWithoutReservation' => true,
                'statusDisplayRequired' => true,
                'planSha256' => hash_file('sha256', $t33PlanPath),
                'resultSha256' => hash_file('sha256', $t33ResultPath),
            ],
            'interpretation' => [
                'descriptiveOnly' => true,
                't24AcceptanceScoringAllowed' => false,
                'legacyFidelityVerdictAllowed' => false,
                'rankingOrSelectionAllowed' => false,
                't24CorpusExcludedFromT31Scoring' => true,
                'monotypeFailureErasedByT34' => false,
            ],
        ];
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

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
