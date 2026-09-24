<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\BattleResult;
use Waar\MicroCombat\CombatResolver;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\PreparedBattle;
use Waar\MicroCombat\SideOutcome;
use Waar\MicroCombat\UnitType;

final readonly class ExperimentRunner
{
    public function __construct(private CombatResolver $resolver = new CombatResolver())
    {
    }

    /** @return array<string, mixed> */
    public function run(ExperimentDefinition $experiment): array
    {
        return $this->runInternal($experiment, null, false);
    }

    /** @return array<string, mixed> */
    public function runDetailed(ExperimentDefinition $experiment): array
    {
        return $this->runInternal($experiment, null, true);
    }

    /**
     * Reuses only the baseline summaries from a report produced for the exact
     * same corpus and sampling contract. Candidate combats are always rerun.
     *
     * @param array<string, mixed> $baselineReport
     * @return array<string, mixed>
     */
    public function runWithBaselineReport(ExperimentDefinition $experiment, array $baselineReport): array
    {
        return $this->runInternal($experiment, $this->validatedBaselineRows($experiment, $baselineReport), false);
    }

    /**
     * @param array<string, mixed> $baselineReport
     * @return array<string, mixed>
     */
    public function runDetailedWithBaselineReport(ExperimentDefinition $experiment, array $baselineReport): array
    {
        return $this->runInternal($experiment, $this->validatedBaselineRows($experiment, $baselineReport), true);
    }

    /**
     * @param null|array<string, array<string, mixed>> $cachedBaselineRows
     * @return array<string, mixed>
     */
    private function runInternal(ExperimentDefinition $experiment, ?array $cachedBaselineRows, bool $includeUnitCounts): array
    {
        $rows = [];
        foreach ($experiment->scenarios as $scenario) {
            $aggregates = [
                'baseline' => null === $cachedBaselineRows ? $this->emptyAggregate() : null,
                'candidate' => $this->emptyAggregate(),
            ];
            foreach (range(0, $experiment->iterations - 1) as $iteration) {
                $seed = self::deriveSeed($experiment->baseSeed, $scenario->id, $iteration);
                if (null === $cachedBaselineRows) {
                    $this->record(
                        $aggregates['baseline'],
                        $this->resolve($experiment->baseline, $scenario, $seed),
                    );
                }
                $this->record(
                    $aggregates['candidate'],
                    $this->resolve($experiment->candidate, $scenario, $seed),
                );
            }
            foreach (CombatSide::cases() as $side) {
                $baseline = null === $cachedBaselineRows
                    ? $this->summarize($aggregates['baseline'][$side->value], $experiment->iterations, $includeUnitCounts)
                    : $cachedBaselineRows[$scenario->id."\0".$side->value];
                $candidate = $this->summarize($aggregates['candidate'][$side->value], $experiment->iterations, $includeUnitCounts);
                $rows[] = [
                    'scenarioId' => $scenario->id,
                    'scenarioLabel' => $scenario->label,
                    'side' => $side->value,
                    'focus' => $scenario->focusSide === $side,
                    'army' => CombatSide::Attacker === $side ? $scenario->attacker : $scenario->defender,
                    'baseline' => $baseline,
                    'candidate' => $candidate,
                    'vector' => $this->vector($baseline, $candidate),
                ];
            }
        }

        return [
            'schemaVersion' => 'waar-micro-wind-tunnel-report/0.1',
            'experiment' => [
                'id' => $experiment->id,
                'label' => $experiment->label,
                'iterations' => $experiment->iterations,
                'baseSeed' => $experiment->baseSeed,
                'scenarioCount' => count($experiment->scenarios),
                'combatCount' => count($experiment->scenarios) * $experiment->iterations * 2,
                'pairedSeeds' => true,
                'selectionPerformed' => false,
            ],
            'axes' => [
                'x' => ['id' => 'winRate', 'label' => 'Taux de victoire'],
                'y' => [
                    ['id' => 'survivors', 'label' => 'Effectifs survivants'],
                    ['id' => 'structure', 'label' => 'Structure restante'],
                    ['id' => 'economicValue', 'label' => 'Valeur économique restante'],
                ],
            ],
            'baseline' => $experiment->baseline->toArray(),
            'candidate' => $experiment->candidate->toArray(),
            'scenarios' => array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $experiment->scenarios),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, array<string, mixed>>
     */
    private function validatedBaselineRows(ExperimentDefinition $experiment, array $report): array
    {
        $metadata = $report['experiment'] ?? null;
        if (!is_array($metadata)
            || 'waar-micro-wind-tunnel-report/0.1' !== ($report['schemaVersion'] ?? null)
            || $experiment->id !== ($metadata['id'] ?? null)
            || $experiment->iterations !== ($metadata['iterations'] ?? null)
            || $experiment->baseSeed !== ($metadata['baseSeed'] ?? null)
            || count($experiment->scenarios) !== ($metadata['scenarioCount'] ?? null)
            || $experiment->baseline->toArray() !== ($report['baseline'] ?? null)
            || array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $experiment->scenarios) !== ($report['scenarios'] ?? null)) {
            throw new \InvalidArgumentException('Baseline report does not match the experiment sampling contract.');
        }
        $rows = $report['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \InvalidArgumentException('Baseline report rows must be a list.');
        }
        $byIdentity = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['scenarioId'] ?? null)
                || !is_string($row['side'] ?? null)
                || !is_array($row['baseline'] ?? null)) {
                throw new \InvalidArgumentException('Baseline report contains an invalid row.');
            }
            $identity = $row['scenarioId']."\0".$row['side'];
            if (isset($byIdentity[$identity])) {
                throw new \InvalidArgumentException('Baseline report row identities must be unique.');
            }
            $byIdentity[$identity] = $row['baseline'];
        }
        foreach ($experiment->scenarios as $scenario) {
            foreach (CombatSide::cases() as $side) {
                if (!isset($byIdentity[$scenario->id."\0".$side->value])) {
                    throw new \InvalidArgumentException('Baseline report does not cover every experiment row.');
                }
            }
        }
        if (2 * count($experiment->scenarios) !== count($byIdentity)) {
            throw new \InvalidArgumentException('Baseline report contains rows outside the experiment corpus.');
        }

        return $byIdentity;
    }

    private function resolve(ExperimentVariant $variant, ExperimentScenario $scenario, int $seed): BattleResult
    {
        return $this->resolver->resolve(new PreparedBattle(
            $variant->ruleset,
            $variant->catalog->prepareArmy($scenario->attacker),
            $variant->catalog->prepareArmy($scenario->defender),
            $seed,
        ));
    }

    /** @return array<string, array<string, mixed>> */
    private function emptyAggregate(): array
    {
        $side = static fn (): array => [
            'wins' => 0,
            'draws' => 0,
            'rounds' => 0,
            'metrics' => [
                'survivors' => ['numerator' => 0, 'denominator' => 0],
                'structure' => ['numerator' => 0, 'denominator' => 0],
                'economicValue' => ['numerator' => 0, 'denominator' => 0],
            ],
            'units' => array_fill_keys(array_column(UnitType::cases(), 'value'), [
                'initial' => 0,
                'survivors' => 0,
                'losses' => 0,
            ]),
        ];

        return [CombatSide::Attacker->value => $side(), CombatSide::Defender->value => $side()];
    }

    /** @param array<string, array<string, mixed>> $aggregate */
    private function record(array &$aggregate, BattleResult $result): void
    {
        foreach (CombatSide::cases() as $side) {
            $row = &$aggregate[$side->value];
            if ($result->winner === $side) {
                ++$row['wins'];
            }
            if (null === $result->winner) {
                ++$row['draws'];
            }
            $row['rounds'] = FixedPoint::checkedAdd($row['rounds'], $result->roundsPlayed);
            $outcome = CombatSide::Attacker === $side ? $result->attacker : $result->defender;
            $this->recordMetrics($row['metrics'], $outcome);
            foreach (UnitType::cases() as $type) {
                $unit = $outcome->unit($type);
                $row['units'][$type->value]['initial'] = FixedPoint::checkedAdd($row['units'][$type->value]['initial'], $unit->initial);
                $row['units'][$type->value]['survivors'] = FixedPoint::checkedAdd($row['units'][$type->value]['survivors'], $unit->survivors);
                $row['units'][$type->value]['losses'] = FixedPoint::checkedAdd($row['units'][$type->value]['losses'], $unit->dead);
            }
            unset($row);
        }
    }

    /** @param array<string, array{numerator: int, denominator: int}> $aggregate */
    private function recordMetrics(array &$aggregate, SideOutcome $outcome): void
    {
        foreach ($outcome->metrics() as $metric => $fraction) {
            $aggregate[$metric]['numerator'] = FixedPoint::checkedAdd($aggregate[$metric]['numerator'], $fraction['numerator']);
            $aggregate[$metric]['denominator'] = FixedPoint::checkedAdd($aggregate[$metric]['denominator'], $fraction['denominator']);
        }
    }

    /** @param array<string, mixed> $aggregate @return array<string, mixed> */
    private function summarize(array $aggregate, int $iterations, bool $includeUnitCounts): array
    {
        $metrics = [];
        foreach ($aggregate['metrics'] as $id => $fraction) {
            $metrics[$id] = $this->fraction($fraction['numerator'], $fraction['denominator']);
        }

        $summary = [
            'wins' => $aggregate['wins'],
            'draws' => $aggregate['draws'],
            'iterations' => $iterations,
            'winRate' => $this->fraction($aggregate['wins'], $iterations),
            'meanRounds' => $aggregate['rounds'] / $iterations,
            'metrics' => $metrics,
        ];
        if ($includeUnitCounts) {
            $summary['units'] = array_map(static fn (array $unit): array => [
                'initial' => $unit['initial'],
                'survivors' => $unit['survivors'],
                'losses' => $unit['losses'],
                'meanInitial' => $unit['initial'] / $iterations,
                'meanSurvivors' => $unit['survivors'] / $iterations,
                'meanLosses' => $unit['losses'] / $iterations,
            ], $aggregate['units']);
        }

        return $summary;
    }

    /** @return array{numerator: int, denominator: int, value: ?float} */
    private function fraction(int $numerator, int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => 0 === $denominator ? null : $numerator / $denominator,
        ];
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $candidate @return array<string, mixed> */
    private function vector(array $baseline, array $candidate): array
    {
        $metrics = [];
        foreach (array_keys($baseline['metrics']) as $id) {
            $metrics[$id] = $this->coordinate($baseline['metrics'][$id]['value'], $candidate['metrics'][$id]['value']);
        }

        return [
            'x' => $this->coordinate($baseline['winRate']['value'], $candidate['winRate']['value']),
            'y' => $metrics,
        ];
    }

    /** @return array{from: ?float, to: ?float, delta: ?float} */
    private function coordinate(?float $from, ?float $to): array
    {
        return ['from' => $from, 'to' => $to, 'delta' => null === $from || null === $to ? null : $to - $from];
    }

    public static function deriveSeed(int $baseSeed, string $scenarioId, int $iteration): int
    {
        if ($baseSeed < 0 || $baseSeed > 2_147_483_647 || $iteration < 0 || '' === $scenarioId) {
            throw new \InvalidArgumentException('Invalid seed derivation input.');
        }
        $bytes = hash('sha256', $baseSeed."\0".$scenarioId."\0".$iteration, true);
        $parts = unpack('Nseed', substr($bytes, 0, 4));
        if (false === $parts) {
            throw new \RuntimeException('Unable to derive experiment seed.');
        }

        return $parts['seed'] & 0x7fffffff;
    }
}
