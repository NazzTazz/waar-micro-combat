<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\BattleResult;
use Waar\MicroCombat\CombatResolver;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\PreparedBattle;
use Waar\MicroCombat\SideOutcome;

final readonly class ExperimentRunner
{
    public function __construct(private CombatResolver $resolver = new CombatResolver()) {}

    /** @return array<string, mixed> */
    public function run(ExperimentDefinition $experiment): array
    {
        $rows = [];
        foreach ($experiment->scenarios as $scenario) {
            $aggregates = [
                'baseline' => $this->emptyAggregate(),
                'candidate' => $this->emptyAggregate(),
            ];
            foreach (range(0, $experiment->iterations - 1) as $iteration) {
                $seed = self::deriveSeed($experiment->baseSeed, $scenario->id, $iteration);
                $this->record(
                    $aggregates['baseline'],
                    $this->resolve($experiment->baseline, $scenario, $seed),
                );
                $this->record(
                    $aggregates['candidate'],
                    $this->resolve($experiment->candidate, $scenario, $seed),
                );
            }
            foreach (CombatSide::cases() as $side) {
                $baseline = $this->summarize($aggregates['baseline'][$side->value], $experiment->iterations);
                $candidate = $this->summarize($aggregates['candidate'][$side->value], $experiment->iterations);
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
    private function summarize(array $aggregate, int $iterations): array
    {
        $metrics = [];
        foreach ($aggregate['metrics'] as $id => $fraction) {
            $metrics[$id] = $this->fraction($fraction['numerator'], $fraction['denominator']);
        }

        return [
            'wins' => $aggregate['wins'],
            'draws' => $aggregate['draws'],
            'iterations' => $iterations,
            'winRate' => $this->fraction($aggregate['wins'], $iterations),
            'meanRounds' => $aggregate['rounds'] / $iterations,
            'metrics' => $metrics,
        ];
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
