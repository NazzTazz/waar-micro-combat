<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\Lcg31;

final class BoundedMonotypeCandidateSearch
{
    public const SCHEMA_VERSION = 'waar-monotype-candidate-search/0.1';
    public const DEFAULT_SEARCH_SEED = 314_159;

    /**
     * @param callable(array<string, mixed>, array<string, string>, int): array<string, mixed> $evaluate
     * @param null|callable(array<string, mixed>): void $onProgress
     * @return array<string, mixed>
     */
    public function search(
        ExperimentDefinition $reference,
        MonotypeSearchSpace $space,
        int $searchSeed,
        int $evaluationBudget,
        ?int $proposalLimit,
        callable $evaluate,
        ?callable $onProgress = null,
    ): array {
        if ($searchSeed < 0 || $searchSeed > 2_147_483_647) {
            throw new \InvalidArgumentException('Search seed must be between 0 and 2^31 - 1.');
        }
        if ($evaluationBudget < 1 || $evaluationBudget > 10_000) {
            throw new \InvalidArgumentException('Evaluation budget must be between 1 and 10000.');
        }
        $proposalLimit ??= 10 * $evaluationBudget;
        if ($proposalLimit < 1 || $proposalLimit > 1_000_000) {
            throw new \InvalidArgumentException('Proposal limit must be between 1 and 1000000.');
        }

        $initialValues = $space->validateExperiment($reference);
        $initialFingerprint = self::canonicalParameterFingerprint($space, $initialValues);
        $records = [];
        $seen = [$initialFingerprint => true];
        $proposalCount = 1;
        $duplicateCount = 0;
        $globalTarget = intdiv($evaluationBudget, 2);
        $globalEvaluated = 0;
        $random = new Lcg31($searchSeed);
        $progression = [];
        $diagnostics = [];

        $initial = $this->evaluateRecord(
            $reference->candidate->toArray(),
            $initialValues,
            $initialFingerprint,
            1,
            1,
            'initial',
            $evaluate,
        );
        $records[] = $initial;
        $best = $initial;
        $this->recordProgress($progression, $best, $initial, 1, $proposalCount, $onProgress);
        $this->recordDrawDiagnostic($diagnostics, $initial);

        while (count($records) < $evaluationBudget && $proposalCount < $proposalLimit) {
            ++$proposalCount;
            $phase = $globalEvaluated < $globalTarget ? 'global' : 'local';
            $proposal = 'global' === $phase
                ? $this->globalProposal($space, $random)
                : $this->localProposal($space, $best['parameters'], $random);
            $quantized = $space->quantize($proposal);
            $fingerprint = self::canonicalParameterFingerprint($space, $quantized);
            if (isset($seen[$fingerprint])) {
                ++$duplicateCount;
                continue;
            }
            $seen[$fingerprint] = true;
            if ('global' === $phase) {
                ++$globalEvaluated;
            }
            $sequence = count($records) + 1;
            $candidate = $space->candidateFromValues(
                $quantized,
                sprintf('t31-candidate-%04d-%s', $sequence, substr($fingerprint, 0, 12)),
                sprintf('T31 candidate %04d', $sequence),
                sprintf('t31.0-%04d', $sequence),
            );
            $record = $this->evaluateRecord(
                $candidate,
                $quantized,
                $fingerprint,
                $sequence,
                $proposalCount,
                $phase,
                $evaluate,
            );
            $records[] = $record;
            if ($this->compare($record, $best) < 0) {
                $best = $record;
            }
            $this->recordProgress($progression, $best, $record, count($records), $proposalCount, $onProgress);
            $this->recordDrawDiagnostic($diagnostics, $record);
        }

        $ranked = $records;
        usort($ranked, $this->compare(...));
        $finalists = array_values(array_slice(array_filter(
            $ranked,
            static fn (array $record): bool => 0 === $record['summary']['drawCount'],
        ), 0, 3));
        $strictCandidates = array_values(array_filter(
            $ranked,
            static fn (array $record): bool => true === $record['summary']['strictAcceptance'],
        ));
        $completed = count($records) === $evaluationBudget;

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'state' => $completed ? 'completed' : 'interrupted-proposal-limit',
            'outcome' => [] !== $strictCandidates
                ? 'strict-objective-reached'
                : 'no-strict-candidate-found-within-budget',
            'searchSeed' => $searchSeed,
            'evaluationBudget' => $evaluationBudget,
            'proposalLimit' => $proposalLimit,
            'evaluatedCandidateCount' => count($records),
            'proposalCount' => $proposalCount,
            'duplicateProposalCount' => $duplicateCount,
            'globalCandidateCount' => $globalEvaluated,
            'localCandidateCount' => count(array_filter($records, static fn (array $record): bool => 'local' === $record['phase'])),
            'strategy' => [
                'initialFirst' => true,
                'globalUniqueTarget' => $globalTarget,
                'globalDistribution' => 'independent-lcg31-state-per-parameter-reduced-modulo-grid-size',
                'localBase' => 'current-best-after-each-evaluation',
                'localDistribution' => 'independent-lcg31-state-per-parameter-reduced-modulo-local-offset-width',
                'localBounds' => 'clamp',
                'quantization' => $space->quantization,
                'deduplication' => 'after-quantization-by-canonical-parameter-sha256',
                'ranking' => 'continuous-loss-ascending-then-parameter-fingerprint-ascending',
            ],
            'initial' => $initial,
            'best' => $best,
            'progression' => $progression,
            'finalists' => $finalists,
            'strictCandidates' => $strictCandidates,
            'invariantDiagnostics' => $diagnostics,
            'evaluations' => $records,
        ];
    }

    /** @param array<string, string|int> $values */
    public static function canonicalParameterFingerprint(MonotypeSearchSpace $space, array $values): string
    {
        return hash('sha256', json_encode(
            $space->quantize($values),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, string> */
    private function globalProposal(MonotypeSearchSpace $space, Lcg31 $random): array
    {
        $proposal = [];
        $step = FixedPoint::parse($space->quantization);
        foreach ($space->parameters as $parameter) {
            $gridSize = intdiv($parameter['maximumMicro'] - $parameter['minimumMicro'], $step) + 1;
            $gridIndex = $random->nextState() % $gridSize;
            $proposal[$parameter['path']] = FixedPoint::format($parameter['minimumMicro'] + $gridIndex * $step);
        }

        return $proposal;
    }

    /**
     * @param array<string, string> $best
     * @return array<string, string>
     */
    private function localProposal(MonotypeSearchSpace $space, array $best, Lcg31 $random): array
    {
        $proposal = [];
        $step = FixedPoint::parse($space->quantization);
        foreach ($space->parameters as $parameter) {
            $spanSteps = intdiv($parameter['maximumMicro'] - $parameter['minimumMicro'], $step);
            $radius = max(1, intdiv($spanSteps + 9, 10));
            $offset = ($random->nextState() % (2 * $radius + 1)) - $radius;
            $micro = FixedPoint::parse($best[$parameter['path']]) + $offset * $step;
            $micro = max($parameter['minimumMicro'], min($parameter['maximumMicro'], $micro));
            $proposal[$parameter['path']] = FixedPoint::format($micro);
        }

        return $proposal;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, string> $parameters
     * @param callable(array<string, mixed>, array<string, string>, int): array<string, mixed> $evaluate
     * @return array<string, mixed>
     */
    private function evaluateRecord(
        array $candidate,
        array $parameters,
        string $fingerprint,
        int $sequence,
        int $proposalNumber,
        string $phase,
        callable $evaluate,
    ): array {
        $evaluation = $evaluate($candidate, $parameters, $sequence);
        $loss = $evaluation['continuousObjective']['value'] ?? null;
        $worst = $evaluation['continuousObjective']['worst'] ?? null;
        $satisfied = $evaluation['acceptance']['satisfied'] ?? null;
        $total = $evaluation['acceptance']['total'] ?? null;
        $draws = $evaluation['draws']['count'] ?? null;
        $strict = $evaluation['strictControls']['passed'] ?? null;
        if ((!is_float($loss) && !is_int($loss))
            || !is_finite((float) $loss)
            || !is_array($worst)
            || !is_string($worst['objectiveId'] ?? null)
            || (!is_float($worst['value'] ?? null) && !is_int($worst['value'] ?? null))
            || !is_finite((float) $worst['value'])
            || !is_int($satisfied)
            || !is_int($total)
            || !is_int($draws)
            || !is_bool($strict)
            || (float) $loss < 0.0
            || (float) $worst['value'] < 0.0
            || 32 !== $total
            || $satisfied < 0
            || $satisfied > $total
            || $draws < 0
            || $strict !== (32 === $satisfied && 0 === $draws)) {
            throw new \InvalidArgumentException('Candidate evaluator returned an invalid T29c evaluation.');
        }

        return [
            'sequence' => $sequence,
            'proposalNumber' => $proposalNumber,
            'phase' => $phase,
            'parameterFingerprint' => $fingerprint,
            'parameters' => $parameters,
            'candidate' => $candidate,
            'summary' => [
                'continuousLoss' => (float) $loss,
                'objectivesSatisfied' => $satisfied,
                'objectiveCount' => $total,
                'worstObjectiveId' => $worst['objectiveId'],
                'worstExcess' => (float) $worst['value'],
                'drawCount' => $draws,
                'strictAcceptance' => $strict,
                'classification' => $strict ? 'strict-accepted' : 'exploratory',
            ],
            'evaluation' => $evaluation,
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        return [$left['summary']['continuousLoss'], $left['parameterFingerprint']]
            <=> [$right['summary']['continuousLoss'], $right['parameterFingerprint']];
    }

    /**
     * @param list<array<string, mixed>> $progression
     * @param array<string, mixed> $best
     * @param array<string, mixed> $current
     * @param null|callable(array<string, mixed>): void $onProgress
     */
    private function recordProgress(
        array &$progression,
        array $best,
        array $current,
        int $evaluated,
        int $proposed,
        ?callable $onProgress,
    ): void {
        $entry = [
            'evaluatedCandidateCount' => $evaluated,
            'proposalCount' => $proposed,
            'currentCandidateId' => $current['candidate']['id'],
            'bestCandidateId' => $best['candidate']['id'],
            'bestParameterFingerprint' => $best['parameterFingerprint'],
            'bestLoss' => $best['summary']['continuousLoss'],
            'bestObjectivesSatisfied' => $best['summary']['objectivesSatisfied'],
            'bestWorstObjectiveId' => $best['summary']['worstObjectiveId'],
            'bestWorstExcess' => $best['summary']['worstExcess'],
        ];
        $progression[] = $entry;
        if (null !== $onProgress) {
            $onProgress($entry);
        }
    }

    /** @param list<array<string, mixed>> $diagnostics @param array<string, mixed> $record */
    private function recordDrawDiagnostic(array &$diagnostics, array $record): void
    {
        if ($record['summary']['drawCount'] > 0) {
            $diagnostics[] = [
                'kind' => 'draw-with-defender-tie-break',
                'candidateId' => $record['candidate']['id'],
                'parameterFingerprint' => $record['parameterFingerprint'],
                'drawCount' => $record['summary']['drawCount'],
            ];
        }
    }
}
