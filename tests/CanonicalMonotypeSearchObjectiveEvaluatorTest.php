<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator;
use Waar\MicroCombat\Experiment\CanonicalMonotypeSearchObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeObjectiveOverlayBuilder;
use Waar\MicroCombat\Experiment\NormalizedEllipseBoundaryPenalty;

require_once dirname(__DIR__).'/autoload.php';

final class CanonicalMonotypeSearchObjectiveEvaluatorTest extends TestCase
{
    public function testPenaltyIsZeroThroughTheAcceptedBoundaryAndThenGrowsRadially(): void
    {
        $penalty = new NormalizedEllipseBoundaryPenalty();

        self::assertSame(0.0, $penalty->fromSquaredDistance(0.0));
        self::assertSame(0.0, $penalty->fromSquaredDistance(0.5));
        self::assertSame(0.0, $penalty->fromSquaredDistance(1.0));
        self::assertSame(0.0, $penalty->fromSquaredDistance(1.0 + AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE));
        self::assertGreaterThan(0.0, $penalty->fromSquaredDistance(1.0000000000010003));
        self::assertEqualsWithDelta(
            2.0 - sqrt(1.0 + AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE),
            $penalty->fromSquaredDistance(4.0),
            1e-15,
        );
    }

    public function testDistanceNormalizesEachAxisByItsOwnRadius(): void
    {
        $distance = (new AcceptanceZoneEvaluator())->normalizedSquaredDistance(0.6, 0.7, [
            'center' => ['x' => 0.5, 'y' => 0.5],
            'radii' => ['x' => 0.1, 'y' => 0.2],
        ]);

        self::assertEqualsWithDelta(2.0, $distance, 1e-12);
    }

    public function testGeometricPointImmediatelyOutsideHasAPositivePenalty(): void
    {
        $zone = [
            'center' => ['x' => 0.5, 'y' => 0.5],
            'radii' => ['x' => 0.1, 'y' => 0.1],
        ];
        $x = 0.5999999555868335;
        $y = 0.50009424776565492;
        $evaluator = new AcceptanceZoneEvaluator();
        $squaredDistance = $evaluator->normalizedSquaredDistance($x, $y, $zone);

        self::assertSame('outside', $evaluator->evaluate($x, $y, $zone));
        self::assertGreaterThan(
            0.0,
            (new NormalizedEllipseBoundaryPenalty())->fromSquaredDistance($squaredDistance),
        );
    }

    public function testRejectsAnInvalidDistance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NormalizedEllipseBoundaryPenalty())->fromSquaredDistance(-0.01);
    }

    public function testAggregatesTheThirtyTwoObjectivesWithEqualWeightAndPublishesTheWorstGap(): void
    {
        [$report, $document] = $this->fixture();
        $evaluation = (new CanonicalMonotypeSearchObjectiveEvaluator())->evaluate($report, $document);

        self::assertSame(CanonicalMonotypeSearchObjectiveEvaluator::SCHEMA_VERSION, $evaluation['schemaVersion']);
        self::assertSame(CanonicalMonotypeSearchObjectiveEvaluator::OBJECTIVE_KIND, $evaluation['continuousObjective']['kind']);
        self::assertSame('minimize', $evaluation['continuousObjective']['direction']);
        self::assertSame('arithmetic-mean', $evaluation['continuousObjective']['aggregation']);
        self::assertSame(1 / 32, $evaluation['objectiveContract']['equalWeight']);
        self::assertSame(0, $evaluation['objectiveContract']['economicValueContributionCount']);
        self::assertSame(0, $evaluation['objectiveContract']['structureContributionCount']);
        self::assertCount(32, $evaluation['entries']);

        $excesses = array_column($evaluation['entries'], 'normalizedBoundaryExcess');
        $contributions = array_column($evaluation['entries'], 'lossContribution');
        self::assertEqualsWithDelta(array_sum($excesses) / 32, $evaluation['continuousObjective']['value'], 1e-12);
        self::assertEqualsWithDelta(array_sum($contributions), $evaluation['continuousObjective']['value'], 1e-12);
        self::assertSame(max($excesses), $evaluation['continuousObjective']['worst']['value']);

        $worstEntry = $evaluation['entries'][array_search(max($excesses), $excesses, true)];
        self::assertSame($worstEntry['objectiveId'], $evaluation['continuousObjective']['worst']['objectiveId']);
        foreach ($evaluation['entries'] as $entry) {
            if ('inside' === $entry['state']) {
                self::assertSame(0.0, $entry['normalizedBoundaryExcess']);
            }
            self::assertEqualsWithDelta($entry['normalizedBoundaryExcess'] / 32, $entry['lossContribution'], 1e-15);
        }
    }

    public function testPointsAtTheCentersHaveNoResidualLoss(): void
    {
        [$report, $document] = $this->fixture();
        $observed = [];
        foreach ($report['rows'] as $row) {
            $observed[$row['scenarioId']][$row['side']] = [
                'x' => $row['candidate']['winRate']['value'],
                'y' => $row['candidate']['metrics']['survivors']['value'],
            ];
        }
        foreach ($document['zones'] as &$zone) {
            $zone['center'] = $observed[$zone['scenarioId']][$zone['side']];
            $zone['radii'] = ['x' => 0.005, 'y' => 0.005];
        }
        unset($zone);

        $evaluation = (new CanonicalMonotypeSearchObjectiveEvaluator())->evaluate($report, $document);

        self::assertSame(0.0, $evaluation['continuousObjective']['value']);
        self::assertSame(32, $evaluation['acceptance']['satisfied']);
        self::assertTrue($evaluation['strictControls']['passed']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function fixture(): array
    {
        $values = json_decode((string) file_get_contents(dirname(__DIR__).'/experiments/t28-defender-tie-break.json'), true, 512, JSON_THROW_ON_ERROR);
        $values['iterations'] = 4;
        $experiment = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));
        $report = (new ExperimentRunner())->run($experiment);
        $document = (new MonotypeObjectiveOverlayBuilder())->build($report)['zonesDocument'];
        foreach ($document['zones'] as &$zone) {
            $zone['approval'] = 'confirmed';
        }
        unset($zone);

        return [$report, $document];
    }
}
