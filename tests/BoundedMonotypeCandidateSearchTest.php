<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\BoundedMonotypeCandidateSearch;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentVariant;
use Waar\MicroCombat\Experiment\MonotypeSearchSpace;

require_once dirname(__DIR__).'/autoload.php';

final class BoundedMonotypeCandidateSearchTest extends TestCase
{
    public function testSearchIsDeterministicBudgetedAndKeepsTheInitialCandidate(): void
    {
        [$reference, $space] = $this->fixture();
        $before = $reference->toArray();
        $evaluate = $this->distanceEvaluator();
        $engine = new BoundedMonotypeCandidateSearch();

        $first = $engine->search($reference, $space, 314_159, 8, null, $evaluate);
        $second = $engine->search($reference, $space, 314_159, 8, null, $evaluate);

        self::assertSame($first, $second);
        self::assertSame('completed', $first['state']);
        self::assertSame(8, $first['evaluatedCandidateCount']);
        self::assertSame(80, $first['proposalLimit']);
        self::assertLessThanOrEqual(80, $first['proposalCount']);
        self::assertSame(4, $first['globalCandidateCount']);
        self::assertSame(3, $first['localCandidateCount']);
        self::assertSame('initial', $first['evaluations'][0]['phase']);
        self::assertSame('roles-a', $first['initial']['candidate']['id']);
        self::assertLessThanOrEqual(
            $first['initial']['summary']['continuousLoss'],
            $first['best']['summary']['continuousLoss'],
        );
        self::assertCount(8, array_unique(array_column($first['evaluations'], 'parameterFingerprint')));
        self::assertSame($before, $reference->toArray());
        foreach ($first['evaluations'] as $record) {
            self::assertSame($record['candidate'], ExperimentVariant::fromArray($record['candidate'])->toArray());
        }
    }

    public function testExactLossTiesUseTheCanonicalParameterFingerprint(): void
    {
        [$reference, $space] = $this->fixture();
        $result = (new BoundedMonotypeCandidateSearch())->search(
            $reference,
            $space,
            7,
            6,
            null,
            fn (array $candidate, array $parameters, int $sequence): array => $this->evaluation(1.0),
        );
        $fingerprints = array_column($result['evaluations'], 'parameterFingerprint');

        self::assertSame(min($fingerprints), $result['best']['parameterFingerprint']);
    }

    public function testDuplicateQuantizedProposalsStopAtTheProposalLimit(): void
    {
        [$reference, , $manifest] = $this->fixture();
        foreach ($manifest['parameters'] as &$parameter) {
            $parameter['minimum'] = $parameter['initial'];
            $parameter['maximum'] = $parameter['initial'];
        }
        unset($parameter);
        $collapsed = MonotypeSearchSpace::fromArray($manifest, $reference);

        $result = (new BoundedMonotypeCandidateSearch())->search(
            $reference,
            $collapsed,
            314_159,
            4,
            5,
            $this->distanceEvaluator(),
        );

        self::assertSame('interrupted-proposal-limit', $result['state']);
        self::assertSame(1, $result['evaluatedCandidateCount']);
        self::assertSame(5, $result['proposalCount']);
        self::assertSame(4, $result['duplicateProposalCount']);
        self::assertSame('no-strict-candidate-found-within-budget', $result['outcome']);
    }

    public function testDrawDiagnosticsExcludeCandidatesFromFinalists(): void
    {
        [$reference, $space] = $this->fixture();
        $result = (new BoundedMonotypeCandidateSearch())->search(
            $reference,
            $space,
            23,
            4,
            null,
            fn (array $candidate, array $parameters, int $sequence): array => $this->evaluation(
                (float) $sequence,
                2 === $sequence ? 1 : 0,
            ),
        );

        self::assertCount(1, $result['invariantDiagnostics']);
        self::assertSame('draw-with-defender-tie-break', $result['invariantDiagnostics'][0]['kind']);
        self::assertNotContains(
            $result['evaluations'][1]['candidate']['id'],
            array_column(array_column($result['finalists'], 'candidate'), 'id'),
        );
        self::assertCount(3, $result['finalists']);
    }

    public function testStrictCandidatesRemainDistinctFromSearchRanking(): void
    {
        [$reference, $space] = $this->fixture();
        $result = (new BoundedMonotypeCandidateSearch())->search(
            $reference,
            $space,
            31,
            3,
            null,
            fn (array $candidate, array $parameters, int $sequence): array => $this->evaluation(
                4.0 - $sequence,
                0,
                2 === $sequence,
            ),
        );

        self::assertSame('strict-objective-reached', $result['outcome']);
        self::assertCount(1, $result['strictCandidates']);
        self::assertSame($result['evaluations'][1]['candidate']['id'], $result['strictCandidates'][0]['candidate']['id']);
        self::assertSame($result['evaluations'][2]['candidate']['id'], $result['best']['candidate']['id']);
    }

    public function testCandidateMetadataDoesNotChangeParameterIdentity(): void
    {
        [, $space] = $this->fixture();
        $values = $space->initialValues();
        $first = $space->candidateFromValues($values, 'first', 'First', 'one');
        $second = $space->candidateFromValues($values, 'second', 'Second', 'two');

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(
            BoundedMonotypeCandidateSearch::canonicalParameterFingerprint($space, $space->validateCandidate(ExperimentVariant::fromArray($first))),
            BoundedMonotypeCandidateSearch::canonicalParameterFingerprint($space, $space->validateCandidate(ExperimentVariant::fromArray($second))),
        );
    }

    public function testRejectsAnEvaluatorThatContradictsStrictAcceptance(): void
    {
        [$reference, $space] = $this->fixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid T29c evaluation');
        (new BoundedMonotypeCandidateSearch())->search(
            $reference,
            $space,
            1,
            1,
            null,
            fn (array $candidate, array $parameters, int $sequence): array => [
                'continuousObjective' => [
                    'value' => 0.0,
                    'worst' => ['objectiveId' => 'fixture-objective', 'value' => 0.0],
                ],
                'acceptance' => ['satisfied' => 31, 'total' => 32],
                'strictControls' => ['passed' => true],
                'draws' => ['count' => 0],
            ],
        );
    }

    /** @return callable(array<string, mixed>, array<string, string>, int): array<string, mixed> */
    private function distanceEvaluator(): callable
    {
        return fn (array $candidate, array $parameters, int $sequence): array => $this->evaluation(
            abs((float) $parameters['units.soldier.attack'] - 9.0),
        );
    }

    /** @return array<string, mixed> */
    private function evaluation(float $loss, int $draws = 0, bool $strict = false): array
    {
        return [
            'continuousObjective' => [
                'value' => $loss,
                'worst' => ['objectiveId' => 'fixture-objective', 'value' => $loss + 1.0],
            ],
            'acceptance' => ['satisfied' => $strict ? 32 : 0, 'total' => 32],
            'strictControls' => ['passed' => $strict],
            'draws' => ['count' => $draws],
        ];
    }

    /** @return array{ExperimentDefinition, MonotypeSearchSpace, array<string, mixed>} */
    private function fixture(): array
    {
        $reference = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t28-defender-tie-break.json');
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/experiments/t30-proposed-search-space.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [$reference, MonotypeSearchSpace::fromArray($manifest, $reference), $manifest];
    }
}
