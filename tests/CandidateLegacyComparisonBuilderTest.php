<?php
namespace Waar\MicroCombat\Tests;
use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\CandidateLegacyComparisonBuilder;
require_once dirname(__DIR__).'/autoload.php';
final class CandidateLegacyComparisonBuilderTest extends TestCase
{
    private function fixture(): array
    {
        $root = dirname(__DIR__).'/experiments/references/';
        return array_map(static fn(string $p): array => json_decode(file_get_contents($root.$p), true, 512, JSON_THROW_ON_ERROR), ['t25a1/legacy-reference.json', 't34-mixed-composition-observation/result.json', 't34-mixed-composition-observation/observation-plan.json']);
    }
    public function testArchivedVectorsPreserveRawObservationsAndNegativeIndices(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $before = serialize([$legacy, $result, $plan]);
        // The candidate must be selected by identity, even after source reordering.
        $result['finalists'] = array_reverse($result['finalists']);
        $data = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
        self::assertCount(12, $data['rows']);
        self::assertEqualsWithDelta(4.5, $data['rows'][0]['deltaPercentagePoints']['winRate'], 1e-10);
        self::assertEqualsWithDelta(-51.2866666666667, $data['rows'][0]['deltaPercentagePoints']['survivors'], 1e-10);
        self::assertEqualsWithDelta(-22.7866666666667, $data['rows'][0]['deltaPercentagePoints']['survivorsDilated'], 1e-10);
        self::assertSame(100.0, $data['globalConformity']['winner']['score']);
        self::assertSame(6, $data['globalConformity']['winner']['matchingScenarios']);
        self::assertEqualsWithDelta(99.25, $data['globalConformity']['winRate']['score'], 1e-10);
        self::assertSame([-40, 100], $data['axes']['dilated']);
        self::assertEqualsWithDelta(70, $data['rows'][0]['metrics']['survivors']['dilated']['legacy'][1], 1e-10);
        self::assertEqualsWithDelta(-38.6344537815126, $data['rows'][11]['metrics']['economicValue']['dilated']['legacy'][1], 1e-10);
        foreach ($data['rows'] as $row) {
            foreach ($row['metrics'] as $metric) {
                self::assertSame($metric['raw']['candidate'], $metric['dilated']['candidate']);
                self::assertSame($metric['raw']['legacy'][0], $metric['dilated']['legacy'][0]);
            }
        }
        $result['finalists'] = array_reverse($result['finalists']);
        self::assertSame($before, serialize([$legacy, $result, $plan]));
    }
    public function testDuplicateObservationsAreRejected(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $legacy['rows'][1] = $legacy['rows'][0];
        $this->expectException(\InvalidArgumentException::class);
        (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
    }
    public function testCompositionMismatchIsRejected(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $legacy['rows'][0]['initial']['byType']['soldier']++;
        $this->expectException(\InvalidArgumentException::class);
        (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
    }
    public function testOutOfRangeTransformationIsNotClamped(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $legacy['rows'][0]['metrics']['operationalSurvivorsRatio']['value'] = 0.9;
        $this->expectException(\InvalidArgumentException::class);
        (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
    }
    public function testAbsentCandidateIsRejected(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $result['finalists'] = [];
        $this->expectException(\InvalidArgumentException::class);
        (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
    }

    public function testGlobalScoresUseAbsoluteErrorsAndCountScenariosOnce(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        $result['finalists'][0]['rows'][0]['winRate'] = 0.6;
        $result['finalists'][0]['rows'][1]['winRate'] = 0.4;
        foreach ($legacy['rows'] as &$row) {
            $row['metrics']['operationalSurvivorsRatio']['value'] = 0.975;
        }
        unset($row);
        foreach ($result['finalists'][0]['rows'] as &$row) {
            $row['metrics']['survivors'] = 0.5;
        }
        unset($row);
        $scores = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan)['globalConformity'];
        self::assertSame(5, $scores['winner']['matchingScenarios']);
        self::assertSame(6, $scores['winner']['totalScenarios']);
        self::assertEqualsWithDelta(90, $scores['winRate']['score'], 1e-10);
        self::assertEqualsWithDelta(100, $scores['survivors']['dilated']['score'], 1e-10);
        self::assertEqualsWithDelta(52.5, $scores['survivors']['raw']['score'], 1e-10);
        $result['finalists'][0]['rows'][0]['winRate'] = 0.5;
        $result['finalists'][0]['rows'][1]['winRate'] = 0.5;
        $scores = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan)['globalConformity'];
        self::assertSame(1, $scores['winner']['withoutMajority']);
        self::assertSame(5, $scores['winner']['matchingScenarios']);
    }

    public function testNarrativesUseActualCompositionsAndRelativeDilatedLosses(): void
    {
        [$legacy, $result, $plan] = $this->fixture();
        // Defender: Legacy 1% losses -> 20 after dilation; candidate 30% losses.
        $legacy['rows'][1]['metrics']['operationalSurvivorsRatio']['value'] = 0.99;
        $result['finalists'][0]['rows'][1]['metrics']['survivors'] = 0.7;
        $data = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
        self::assertCount(6, $data['scenarioNotes']);
        $note = $data['scenarioNotes'][0];
        self::assertStringContainsString('100 % de soldats (30)', $note['text']);
        self::assertStringContainsString('l’issue majoritaire reste la même', $note['text']);
        self::assertStringContainsString('augmentées de 50 % par rapport au Legacy dilaté ×20', $note['text']);
        self::assertEqualsWithDelta(10, $note['defenderLosses']['deltaIndexPoints'], 1e-10);
        self::assertEqualsWithDelta(50, $note['defenderLosses']['relativeChangePercent'], 1e-10);
        self::assertStringContainsString('73,33 % de soldats (22)', $data['scenarioNotes'][1]['text']);
        self::assertStringContainsString('28,57 % de lanciers (8)', $data['scenarioNotes'][1]['text']);
        $result['finalists'][0]['rows'][1]['metrics']['survivors'] = 0.9;
        $data = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
        self::assertStringContainsString('réduites de 50 %', $data['scenarioNotes'][0]['text']);
        $legacy['rows'][1]['metrics']['operationalSurvivorsRatio']['value'] = 1;
        $data = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
        self::assertNull($data['scenarioNotes'][0]['defenderLosses']['relativeChangePercent']);
        self::assertStringContainsString('variation relative est non définie', $data['scenarioNotes'][0]['text']);
    }
}
