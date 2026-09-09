<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\CombatTieBreakPolicy;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;

require_once dirname(__DIR__).'/autoload.php';

final class ExperimentRunnerTest extends TestCase
{
    public function testT24ExportsBothSidesAndAllThreeAxesFromOneRun(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json');
        $report = (new ExperimentRunner())->run($experiment);

        self::assertSame(6, $report['experiment']['scenarioCount']);
        self::assertSame(2_400, $report['experiment']['combatCount']);
        self::assertTrue($report['experiment']['pairedSeeds']);
        self::assertFalse($report['experiment']['selectionPerformed']);
        self::assertCount(12, $report['rows']);
        self::assertSame(['survivors', 'structure', 'economicValue'], array_column($report['axes']['y'], 'id'));

        foreach ($report['rows'] as $row) {
            self::assertSame(['survivors', 'structure', 'economicValue'], array_keys($row['baseline']['metrics']));
            self::assertSame(['survivors', 'structure', 'economicValue'], array_keys($row['candidate']['metrics']));
            foreach ($row['baseline']['metrics'] as $metric) {
                self::assertGreaterThan(0, $metric['denominator']);
            }
            foreach ($row['candidate']['metrics'] as $metric) {
                self::assertGreaterThan(0, $metric['denominator']);
            }
        }

        $control = $this->row($report['rows'], 'soldier-control', 'attacker');
        self::assertSame(0.0, $control['vector']['x']['delta']);
        self::assertSame(0.0, $control['vector']['y']['survivors']['delta']);
        self::assertSame(0.0, $control['vector']['y']['structure']['delta']);
        self::assertSame(0.0, $control['vector']['y']['economicValue']['delta']);

        $spears = $this->row($report['rows'], 'spears-against-knights', 'defender');
        self::assertGreaterThan(0.5, $spears['vector']['x']['delta'], 'The advertised defensive spear counter must be visible.');

        $screen = $this->row($report['rows'], 'spearman-screen', 'defender');
        self::assertGreaterThan(0.05, $screen['vector']['x']['from']);
        self::assertLessThan(0.95, $screen['vector']['x']['from']);
        self::assertGreaterThan(0.05, $screen['vector']['x']['to']);
        self::assertLessThan(0.95, $screen['vector']['x']['to']);

        $archers = $this->row($report['rows'], 'archers-against-spears', 'attacker');
        self::assertGreaterThan(0.05, $archers['vector']['x']['from']);
        self::assertLessThan(0.95, $archers['vector']['x']['from']);
        self::assertGreaterThan(0.05, $archers['vector']['x']['to']);
        self::assertLessThan(0.95, $archers['vector']['x']['to']);
        self::assertLessThan(-0.4, $archers['vector']['x']['delta'], 'The corpus must keep the first candidate\'s Archer regression visible.');
    }

    public function testIdenticalVariantsProduceIdenticalAggregatesWithPairedSeeds(): void
    {
        $values = json_decode(file_get_contents(dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json'), true, 512, JSON_THROW_ON_ERROR);
        $values['iterations'] = 25;
        $values['candidate'] = $values['baseline'];
        $values['candidate']['id'] = 'identical-candidate';
        $values['scenarios'] = [$values['scenarios'][1]];
        $experiment = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));
        $report = (new ExperimentRunner())->run($experiment);

        self::assertCount(2, $report['rows']);
        foreach ($report['rows'] as $row) {
            self::assertSame($row['baseline'], $row['candidate']);
            self::assertSame(0.0, $row['vector']['x']['delta']);
            foreach ($row['vector']['y'] as $coordinate) {
                self::assertSame(0.0, $coordinate['delta']);
            }
        }
    }

    public function testExportedExperimentCanBeEditedAndRunAgain(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json');
        $exported = json_encode($experiment->toArray(), JSON_THROW_ON_ERROR);

        $reloaded = ExperimentDefinition::fromJson($exported);

        self::assertSame($experiment->id, $reloaded->id);
        self::assertSame($experiment->baseline->ruleset->randomSpreadMicro, $reloaded->baseline->ruleset->randomSpreadMicro);
        self::assertSame($experiment->candidate->counters, $reloaded->candidate->counters);
        self::assertCount(6, $reloaded->scenarios);
    }

    public function testT28DeclaresTheDefenderTieBreakWithoutChangingHistoricalManifests(): void
    {
        $historical = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json');
        $t28 = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t28-defender-tie-break.json');

        self::assertArrayNotHasKey('tieBreakPolicy', $historical->baseline->toArray());
        self::assertSame(CombatTieBreakPolicy::Draw, $historical->baseline->ruleset->tieBreakPolicy);
        self::assertSame('defender', $t28->baseline->toArray()['tieBreakPolicy']);
        self::assertSame('defender', $t28->candidate->toArray()['tieBreakPolicy']);
        self::assertSame(CombatTieBreakPolicy::Defender, $t28->baseline->ruleset->tieBreakPolicy);
        self::assertSame(CombatTieBreakPolicy::Defender, $t28->candidate->ruleset->tieBreakPolicy);
        self::assertEquals($historical->scenarios, $t28->scenarios);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function row(array $rows, string $scenarioId, string $side): array
    {
        foreach ($rows as $row) {
            if ($scenarioId === $row['scenarioId'] && $side === $row['side']) {
                return $row;
            }
        }

        self::fail(sprintf('Missing row %s/%s.', $scenarioId, $side));
    }
}
