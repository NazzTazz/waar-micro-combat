<?php

namespace Waar\MicroCombat\Experiment;

final class FinalistStabilityAnalyzer
{
    /**
     * @param list<array<string, mixed>> $batchEvaluations
     * @param array<string, mixed> $aggregateEvaluation
     * @return array{status: string, variationBetweenBatches: list<array<string, mixed>>}
     */
    public function analyze(array $batchEvaluations, array $aggregateEvaluation): array
    {
        if ([] === $batchEvaluations) {
            throw new \InvalidArgumentException('At least one batch evaluation is required.');
        }
        $byBatch = [];
        $anyDraw = (int) ($aggregateEvaluation['draws']['count'] ?? 0) > 0;
        foreach ($batchEvaluations as $batch => $evaluation) {
            $anyDraw = $anyDraw || (int) ($evaluation['draws']['count'] ?? 0) > 0;
            foreach ($evaluation['entries'] ?? [] as $entry) {
                $id = $entry['objectiveId'] ?? null;
                if (!is_string($id) || isset($byBatch[$batch][$id])) {
                    throw new \InvalidArgumentException('Batch evaluations must contain unique objective identifiers.');
                }
                $byBatch[$batch][$id] = $entry;
            }
        }
        $ids = array_keys($byBatch[0] ?? []);
        if ([] === $ids) {
            throw new \InvalidArgumentException('Batch evaluations contain no objectives.');
        }

        $variation = [];
        $anyStateChange = false;
        foreach ($ids as $id) {
            $entries = [];
            foreach ($byBatch as $batch => $entriesById) {
                if (!isset($entriesById[$id]) || array_keys($entriesById) !== $ids) {
                    throw new \InvalidArgumentException('Batch evaluations must expose the same ordered objectives.');
                }
                $entries[] = $entriesById[$id];
            }
            $states = array_values(array_unique(array_column($entries, 'state')));
            $stateChanged = count($states) > 1;
            $anyStateChange = $anyStateChange || $stateChanged;
            $xs = array_map(static fn (array $entry): float => (float) $entry['observed']['x'], $entries);
            $ys = array_map(static fn (array $entry): float => (float) $entry['observed']['y'], $entries);
            $variation[] = [
                'objectiveId' => $id,
                'label' => 'variation entre lots',
                'winRate' => ['minimum' => min($xs), 'maximum' => max($xs)],
                'survivors' => ['minimum' => min($ys), 'maximum' => max($ys)],
                'batchesSatisfied' => count(array_filter($entries, static fn (array $entry): bool => 'inside' === $entry['state'])),
                'batchCount' => count($entries),
                'states' => $states,
                'stateChanged' => $stateChanged,
            ];
        }

        $allStrict = true === ($aggregateEvaluation['strictControls']['passed'] ?? false);
        foreach ($batchEvaluations as $evaluation) {
            $allStrict = $allStrict && true === ($evaluation['strictControls']['passed'] ?? false);
        }
        $status = $anyDraw
            ? 'invariant-nuls-en-echec'
            : ($anyStateChange ? 'variable-selon-le-lot' : ($allStrict ? 'stable-sur-les-lots' : 'objectifs-non-atteints'));

        return ['status' => $status, 'variationBetweenBatches' => $variation];
    }
}
