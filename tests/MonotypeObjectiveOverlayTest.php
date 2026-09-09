<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeObjectiveOverlayBuilder;

require_once dirname(__DIR__).'/autoload.php';

final class MonotypeObjectiveOverlayTest extends TestCase
{
    public function testDefinesTheCompleteOrderedMatrixAtOneExactCommonBudget(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json');

        self::assertCount(16, $experiment->scenarios);
        $costs = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];
        $pairs = [];
        foreach ($experiment->scenarios as $scenario) {
            $attacker = array_filter($scenario->attacker);
            $defender = array_filter($scenario->defender);
            self::assertCount(1, $attacker);
            self::assertCount(1, $defender);
            $attackerUnit = array_key_first($attacker);
            $defenderUnit = array_key_first($defender);
            self::assertSame(400_400, $attacker[$attackerUnit] * $costs[$attackerUnit]);
            self::assertSame(400_400, $defender[$defenderUnit] * $costs[$defenderUnit]);
            $pairs[] = $attackerUnit.'>'.$defenderUnit;
        }

        self::assertCount(16, array_unique($pairs));
        self::assertContains('soldier>knight', $pairs);
        self::assertContains('knight>soldier', $pairs);
        self::assertContains('archer>archer', $pairs);
    }

    public function testBuildsOneDraftObjectivePerSideWithEconomicViewAndNoStructureConstraint(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json');
        $report = (new ExperimentRunner())->run($experiment);
        $overlay = (new MonotypeObjectiveOverlayBuilder())->build($report);

        self::assertSame(MonotypeObjectiveOverlayBuilder::SCHEMA_VERSION, $overlay['schemaVersion']);
        self::assertSame(400_400, $overlay['designSurface']['commonBudget']);
        self::assertFalse($overlay['designSurface']['t24ContributesConstraints']);
        self::assertFalse($overlay['legacyReference']['available']);
        self::assertSame(['tip'], $overlay['ui']['editableEndpoints']);
        self::assertCount(32, $overlay['rows']);
        self::assertTrue($overlay['ui']['canonicalMonotypeObjectives']);
        self::assertCount(32, $overlay['zonesDocument']['zones']);
        self::assertSame(['survivors'], array_values(array_unique(array_column($overlay['zonesDocument']['zones'], 'yMetric'))));
        foreach ($overlay['rows'] as $row) {
            self::assertSame($row['zoneIds']['survivors'], $row['zoneIds']['economicValue']);
            self::assertArrayNotHasKey('structure', $row['zoneIds']);
            foreach (['from', 'to'] as $endpoint) {
                self::assertEqualsWithDelta($row['micro']['vector']['y']['survivors'][$endpoint], $row['micro']['vector']['y']['economicValue'][$endpoint], 1e-12);
            }
        }

        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[$row['scenarioId']."\0".$row['side']] = $row;
        }
        foreach ($overlay['zonesDocument']['zones'] as $zone) {
            self::assertSame('tip', $zone['endpoint']);
            self::assertSame('draft', $zone['approval']);
            self::assertSame('observation', $zone['source']['kind']);
            self::assertFalse($zone['source']['modifiedManually']);
            $row = $rows[$zone['scenarioId']."\0".$zone['side']];
            $attacker = $rows[$zone['scenarioId']."\0attacker"];
            $expectedX = 'attacker' === $zone['side'] ? $attacker['vector']['x']['to'] : 1.0 - $attacker['vector']['x']['to'];
            self::assertSame($expectedX, $zone['center']['x']);
            self::assertSame($row['vector']['x']['to'], $zone['source']['originalCenter']['x'], 'Original measured results must not be rewritten to remove draws.');
            self::assertSame($row['vector']['y'][$zone['yMetric']]['to'], $zone['center']['y']);
        }
    }

    public function testRejectsAnUnequalBudgetEvenWhenScenarioIdsStayTheSame(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json');
        $report = (new ExperimentRunner())->run($experiment);
        ++$report['scenarios'][0]['defender']['soldier'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exact budget');
        (new MonotypeObjectiveOverlayBuilder())->build($report);
    }
}
