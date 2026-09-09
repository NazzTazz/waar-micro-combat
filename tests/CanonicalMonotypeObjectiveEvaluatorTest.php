<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\CanonicalMonotypeObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeObjectiveOverlayBuilder;

require_once dirname(__DIR__).'/autoload.php';

final class CanonicalMonotypeObjectiveEvaluatorTest extends TestCase
{
    public function testScoresEveryCanonicalObjectiveExactlyOnceAndReportsDrawsSeparately(): void
    {
        [$report, $document] = $this->fixture();
        $evaluation = (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $document);

        self::assertSame(CanonicalMonotypeObjectiveEvaluator::SCHEMA_VERSION, $evaluation['schemaVersion']);
        self::assertSame('draw', $evaluation['candidate']['tieBreakPolicy']);
        self::assertSame(32, $evaluation['objectiveContract']['count']);
        self::assertTrue($evaluation['objectiveContract']['oneContributionPerObjective']);
        self::assertCount(32, $evaluation['entries']);
        self::assertCount(32, array_unique(array_column($evaluation['entries'], 'objectiveId')));
        self::assertSame(['survivors'], array_values(array_unique(array_column($evaluation['entries'], 'objectiveMetric'))));
        self::assertSame($evaluation['score']['satisfied'], array_sum(array_column($evaluation['entries'], 'scoreContribution')));
        self::assertSame($evaluation['score']['satisfied'] / 32, $evaluation['score']['rate']);
        self::assertSame(32 === $evaluation['score']['satisfied'], $evaluation['strictControls']['allObjectivesSatisfied']);
        self::assertSame($evaluation['draws']['count'] === 0, $evaluation['strictControls']['noDraws']);
        self::assertSame(
            $evaluation['strictControls']['allObjectivesSatisfied'] && $evaluation['strictControls']['noDraws'],
            $evaluation['strictControls']['passed'],
        );
    }

    public function testRejectsTheFormerNinetySixZoneDocument(): void
    {
        [$report, $document] = $this->fixture();
        $canonicalZones = $document['zones'];
        foreach (['structure', 'economicValue'] as $metric) {
            foreach ($canonicalZones as $zone) {
                $copy = $zone;
                $copy['id'] = preg_replace('/survivors$/', $metric, $copy['id']);
                $copy['yMetric'] = $metric;
                $document['zones'][] = $copy;
            }
        }
        self::assertCount(96, $document['zones']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 32 canonical objectives');
        (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $document);
    }

    public function testRejectsAHiddenDuplicateOrAnUnconfirmedObjective(): void
    {
        [$report, $document] = $this->fixture();
        $document['zones'][1] = $document['zones'][0];

        try {
            (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $document);
            self::fail('A duplicate objective should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('known and unique', $exception->getMessage());
        }

        [, $document] = $this->fixture();
        $document['zones'][0]['approval'] = 'draft';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enabled and confirmed survivors objectives');
        (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $document);
    }

    public function testRejectsWinRatesThatAreNoLongerComplementary(): void
    {
        [$report, $document] = $this->fixture();
        $document['zones'][1]['center']['x'] += 0.01;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('complementary win rates');
        (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $document);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function fixture(): array
    {
        $values = json_decode((string) file_get_contents(dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json'), true, 512, JSON_THROW_ON_ERROR);
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
