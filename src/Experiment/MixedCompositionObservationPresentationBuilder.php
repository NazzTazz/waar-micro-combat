<?php

namespace Waar\MicroCombat\Experiment;

final class MixedCompositionObservationPresentationBuilder
{
    /** @param array<string, mixed> $plan @param array<string, mixed> $result @return array<string, mixed> */
    public function build(array $plan, array $result, string $planSha256): array
    {
        if (MixedCompositionObservationPlanBuilder::SCHEMA_VERSION !== ($plan['schemaVersion'] ?? null)
            || MixedCompositionObservationRunner::SCHEMA_VERSION !== ($result['schemaVersion'] ?? null)
            || ($plan['id'] ?? null) !== ($result['planId'] ?? null)
            || 'completed' !== ($result['state'] ?? null)) {
            throw new \InvalidArgumentException('T34 plan and result do not form a complete observation.');
        }

        return [
            'schemaVersion' => 'waar-mixed-composition-observation-presentation/0.1',
            'run' => [
                'planId' => $plan['id'],
                'planSha256' => $planSha256,
                'state' => 'completed',
                'label' => 'T34 — observation des compositions mixtes T24',
            ],
            'sampling' => $result['measurement'],
            'axes' => [
                'x' => ['id' => 'winRate', 'label' => 'Taux de victoire'],
                'y' => [
                    ['id' => 'survivors', 'label' => 'Effectifs survivants'],
                    ['id' => 'structure', 'label' => 'Structure restante'],
                    ['id' => 'economicValue', 'label' => 'Valeur économique restante'],
                ],
            ],
            'scenarios' => $plan['corpus']['scenarios'],
            'initial' => $result['initial'],
            'finalists' => $result['finalists'],
            'observations' => $result['observations'],
            'historicalReference' => $plan['historicalReference'],
            'interpretation' => $result['interpretation'],
            'browserContract' => [
                'simulationAllowed' => false,
                'candidateMutationAllowed' => false,
                'acceptanceInferenceAllowed' => false,
                'axisCount' => 3,
                'scenarioCount' => count($plan['corpus']['scenarios']),
                'sideCount' => 2,
                'exportsContainMeasuredValues' => true,
            ],
        ];
    }
}
