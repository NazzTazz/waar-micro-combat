<?php

namespace Waar\MicroCombat\Experiment;

final readonly class CanonicalMonotypeSearchObjectiveEvaluator
{
    public const SCHEMA_VERSION = 'waar-monotype-search-objective/0.1';
    public const OBJECTIVE_KIND = 'mean-normalized-ellipse-boundary-excess';

    public function __construct(
        private CanonicalMonotypeObjectiveEvaluator $acceptanceEvaluator = new CanonicalMonotypeObjectiveEvaluator(),
        private NormalizedEllipseBoundaryPenalty $penalty = new NormalizedEllipseBoundaryPenalty(),
    ) {
    }

    /**
     * @param array<string, mixed> $microReport
     * @param array<string, mixed> $objectiveDocument
     * @return array<string, mixed>
     */
    public function evaluate(array $microReport, array $objectiveDocument): array
    {
        $acceptance = $this->acceptanceEvaluator->evaluate($microReport, $objectiveDocument);
        $objectiveCount = $acceptance['objectiveContract']['count'];
        $weight = 1 / $objectiveCount;
        $totalExcess = 0.0;
        $worstExcess = -1.0;
        $worstObjectiveId = null;
        $entries = [];

        foreach ($acceptance['entries'] as $entry) {
            $squaredDistance = $entry['normalizedSquaredDistance'];
            $radialDistance = sqrt($squaredDistance);
            $boundaryExcess = $this->penalty->fromSquaredDistance($squaredDistance);
            $weightedContribution = $weight * $boundaryExcess;
            $totalExcess += $boundaryExcess;
            if ($boundaryExcess > $worstExcess) {
                $worstExcess = $boundaryExcess;
                $worstObjectiveId = $entry['objectiveId'];
            }

            $entries[] = $entry + [
                'normalizedRadialDistance' => $radialDistance,
                'normalizedBoundaryExcess' => $boundaryExcess,
                'objectiveWeight' => $weight,
                'lossContribution' => $weightedContribution,
            ];
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'experimentId' => $acceptance['experimentId'],
            'candidate' => $acceptance['candidate'],
            'objectiveContract' => $acceptance['objectiveContract'] + [
                'equalWeight' => $weight,
                'economicValueContributionCount' => 0,
                'structureContributionCount' => 0,
            ],
            'continuousObjective' => [
                'kind' => self::OBJECTIVE_KIND,
                'direction' => 'minimize',
                'aggregation' => 'arithmetic-mean',
                'boundaryToleranceSquared' => AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE,
                'value' => $totalExcess / $objectiveCount,
                'totalExcess' => $totalExcess,
                'worst' => [
                    'objectiveId' => $worstObjectiveId,
                    'value' => $worstExcess,
                ],
            ],
            'acceptance' => $acceptance['score'],
            'strictControls' => $acceptance['strictControls'],
            'draws' => $acceptance['draws'],
            'entries' => $entries,
        ];
    }
}
