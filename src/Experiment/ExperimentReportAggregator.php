<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\FixedPoint;

final class ExperimentReportAggregator
{
    /**
     * Pools exact counters and fractions from compatible reports.
     *
     * @param non-empty-list<array<string, mixed>> $reports
     * @return array<string, mixed>
     */
    public function aggregate(array $reports): array
    {
        if ([] === $reports || !array_is_list($reports)) {
            throw new \InvalidArgumentException('At least one ordered experiment report is required.');
        }

        $first = $reports[0];
        $this->validateReport($first);
        $baseSeeds = [];
        $iterations = 0;
        $combatCount = 0;
        foreach ($reports as $report) {
            $this->validateCompatibility($first, $report);
            $seed = $report['experiment']['baseSeed'];
            if (in_array($seed, $baseSeeds, true)) {
                throw new \InvalidArgumentException('Aggregate reports must use distinct base seeds.');
            }
            $baseSeeds[] = $seed;
            $iterations = FixedPoint::checkedAdd($iterations, $report['experiment']['iterations']);
            $combatCount = FixedPoint::checkedAdd($combatCount, $report['experiment']['combatCount']);
        }

        $rows = [];
        foreach (array_keys($first['rows']) as $index) {
            $reference = $first['rows'][$index];
            $baseline = $this->poolSample(array_map(static fn (array $report): array => $report['rows'][$index]['baseline'], $reports));
            $candidate = $this->poolSample(array_map(static fn (array $report): array => $report['rows'][$index]['candidate'], $reports));
            $rows[] = [
                'scenarioId' => $reference['scenarioId'],
                'scenarioLabel' => $reference['scenarioLabel'],
                'side' => $reference['side'],
                'focus' => $reference['focus'],
                'army' => $reference['army'],
                'baseline' => $baseline,
                'candidate' => $candidate,
                'vector' => $this->vector($baseline, $candidate),
            ];
        }

        $experiment = $first['experiment'];
        $experiment['iterations'] = $iterations;
        $experiment['baseSeed'] = null;
        $experiment['baseSeeds'] = $baseSeeds;
        $experiment['combatCount'] = $combatCount;
        $experiment['aggregation'] = 'counter-sum-before-objective-evaluation';
        $experiment['batchCount'] = count($reports);

        return [
            'schemaVersion' => $first['schemaVersion'],
            'experiment' => $experiment,
            'axes' => $first['axes'],
            'baseline' => $first['baseline'],
            'candidate' => $first['candidate'],
            'scenarios' => $first['scenarios'],
            'rows' => $rows,
        ];
    }

    /** @param list<array<string, mixed>> $samples @return array<string, mixed> */
    private function poolSample(array $samples): array
    {
        $wins = 0;
        $draws = 0;
        $iterations = 0;
        $rounds = 0;
        $metrics = [];
        foreach ($samples as $sample) {
            $wins = FixedPoint::checkedAdd($wins, $this->integer($sample, 'wins'));
            $draws = FixedPoint::checkedAdd($draws, $this->integer($sample, 'draws'));
            $sampleIterations = $this->integer($sample, 'iterations');
            $iterations = FixedPoint::checkedAdd($iterations, $sampleIterations);
            $sampleRounds = (float) ($sample['meanRounds'] ?? NAN) * $sampleIterations;
            if (!is_finite($sampleRounds) || abs($sampleRounds - round($sampleRounds)) > 1e-8) {
                throw new \InvalidArgumentException('Mean rounds cannot be converted back to an exact counter.');
            }
            $rounds = FixedPoint::checkedAdd($rounds, (int) round($sampleRounds));
            foreach (['survivors', 'structure', 'economicValue'] as $metric) {
                $fraction = $sample['metrics'][$metric] ?? null;
                if (!is_array($fraction)) {
                    throw new \InvalidArgumentException(sprintf('Missing metric "%s".', $metric));
                }
                $metrics[$metric] ??= ['numerator' => 0, 'denominator' => 0];
                $metrics[$metric]['numerator'] = FixedPoint::checkedAdd($metrics[$metric]['numerator'], $this->integer($fraction, 'numerator'));
                $metrics[$metric]['denominator'] = FixedPoint::checkedAdd($metrics[$metric]['denominator'], $this->integer($fraction, 'denominator'));
            }
        }

        $pooledMetrics = [];
        foreach ($metrics as $id => $fraction) {
            $pooledMetrics[$id] = $this->fraction($fraction['numerator'], $fraction['denominator']);
        }

        return [
            'wins' => $wins,
            'draws' => $draws,
            'iterations' => $iterations,
            'winRate' => $this->fraction($wins, $iterations),
            'meanRounds' => $rounds / $iterations,
            'metrics' => $pooledMetrics,
        ];
    }

    /** @return array{numerator: int, denominator: int, value: ?float} */
    private function fraction(int $numerator, int $denominator): array
    {
        return ['numerator' => $numerator, 'denominator' => $denominator, 'value' => 0 === $denominator ? null : $numerator / $denominator];
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $candidate @return array<string, mixed> */
    private function vector(array $baseline, array $candidate): array
    {
        $coordinate = static fn (?float $from, ?float $to): array => ['from' => $from, 'to' => $to, 'delta' => null === $from || null === $to ? null : $to - $from];
        $metrics = [];
        foreach (array_keys($baseline['metrics']) as $id) {
            $metrics[$id] = $coordinate($baseline['metrics'][$id]['value'], $candidate['metrics'][$id]['value']);
        }

        return ['x' => $coordinate($baseline['winRate']['value'], $candidate['winRate']['value']), 'y' => $metrics];
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $report */
    private function validateCompatibility(array $first, array $report): void
    {
        $this->validateReport($report);
        foreach (['schemaVersion', 'axes', 'baseline', 'candidate', 'scenarios'] as $key) {
            if ($first[$key] !== $report[$key]) {
                throw new \InvalidArgumentException(sprintf('Reports differ on "%s".', $key));
            }
        }
        foreach (['id', 'label', 'scenarioCount', 'pairedSeeds', 'selectionPerformed'] as $key) {
            if ($first['experiment'][$key] !== $report['experiment'][$key]) {
                throw new \InvalidArgumentException(sprintf('Reports differ on experiment "%s".', $key));
            }
        }
        foreach ($first['rows'] as $index => $row) {
            $other = $report['rows'][$index] ?? null;
            foreach (['scenarioId', 'scenarioLabel', 'side', 'focus', 'army'] as $key) {
                if (!is_array($other) || $row[$key] !== ($other[$key] ?? null)) {
                    throw new \InvalidArgumentException('Reports do not expose the same ordered observations.');
                }
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function validateReport(array $report): void
    {
        if ('waar-micro-wind-tunnel-report/0.1' !== ($report['schemaVersion'] ?? null)
            || !is_array($report['experiment'] ?? null)
            || !is_int($report['experiment']['iterations'] ?? null)
            || !is_int($report['experiment']['baseSeed'] ?? null)
            || !is_int($report['experiment']['combatCount'] ?? null)
            || !is_array($report['rows'] ?? null)
            || !array_is_list($report['rows'])) {
            throw new \InvalidArgumentException('Invalid experiment report.');
        }
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key): int
    {
        if (!is_int($values[$key] ?? null) || $values[$key] < 0) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a non-negative integer.', $key));
        }

        return $values[$key];
    }
}
