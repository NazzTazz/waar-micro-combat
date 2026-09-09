<?php

namespace Waar\MicroCombat\Experiment;

final class FinalistStabilityPresentationBuilder
{
    public const SCHEMA_VERSION = 'waar-monotype-finalist-stability-presentation/0.1';

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $objectives
     * @return array<string, mixed>
     */
    public function build(array $result, array $plan, array $objectives, string $planSha256): array
    {
        if (FinalistStabilityRunner::SCHEMA_VERSION !== ($result['schemaVersion'] ?? null)
            || FinalistStabilityPlanBuilder::SCHEMA_VERSION !== ($plan['schemaVersion'] ?? null)
            || ($result['planId'] ?? null) !== ($plan['id'] ?? null)
            || 32 !== count($objectives['zones'] ?? [])) {
            throw new \InvalidArgumentException('T33 result, plan or objectives are incompatible.');
        }
        $zoneById = [];
        foreach ($objectives['zones'] as $zone) {
            $zoneById[$zone['id']] = $zone;
        }
        $presented = [];
        foreach ($result['candidates'] as $index => $candidate) {
            $planned = $plan['candidates'][$index] ?? null;
            if (!is_array($planned)
                || ($candidate['id'] ?? null) !== ($planned['id'] ?? null)
                || ($candidate['rank'] ?? null) !== ($planned['rank'] ?? null)
                || ($candidate['sha256'] ?? null) !== ($planned['sha256'] ?? null)) {
                throw new \InvalidArgumentException('T33 presentation candidate order differs from the frozen plan.');
            }
            $presented[] = $this->candidate($candidate, $zoneById, $plan['id'], $planSha256);
        }
        $initial = array_shift($presented);
        if (null === $initial || 3 !== count($presented)) {
            throw new \InvalidArgumentException('T33 presentation requires one initial and three finalists.');
        }
        $scenarioIds = array_values(array_unique(array_column($initial['rows'], 'scenarioId')));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'title' => 'T33 — stabilité des finalistes sur cinq lots réservés',
            'run' => [
                'planId' => $plan['id'],
                'state' => $result['state'],
                'complete' => 'completed' === $result['state'],
                'statusLabel' => 'Validation T33 exécutée sur le plan réservé',
                'statusDetail' => sprintf('%d lots × %s répétitions · %s combats réels', $result['measurement']['batchCount'], number_format($result['measurement']['iterationsPerScenarioAndCandidate'], 0, ',', ' '), number_format($result['execution']['actualCombatCount'], 0, ',', ' ')),
                'source' => ['planSha256' => $planSha256, 'objectivesSha256' => $plan['inputs']['sha256']['objectives'], 'corpusSha256' => $plan['inputs']['sha256']['corpus']],
            ],
            'experiment' => [
                'id' => $result['candidates'][0]['aggregate']['report']['experiment']['id'],
                'label' => $result['candidates'][0]['aggregate']['report']['experiment']['label'],
                'iterations' => $result['candidates'][0]['aggregate']['report']['experiment']['iterations'],
                'scenarioCount' => count($scenarioIds),
                'batchCount' => $result['measurement']['batchCount'],
            ],
            'axes' => [
                'x' => ['id' => 'winRate', 'label' => 'Taux de victoire'],
                'y' => [
                    ['id' => 'survivors', 'label' => 'Effectifs survivants', 'objectiveMode' => 'canonical'],
                    ['id' => 'economicValue', 'label' => 'Valeur économique restante', 'objectiveMode' => 'same-as-survivors'],
                    ['id' => 'structure', 'label' => 'Structure restante', 'objectiveMode' => 'diagnostic-only'],
                ],
            ],
            'objectiveDocument' => [
                'id' => $objectives['generation']['id'] ?? null,
                'readOnly' => true,
                'count' => count($zoneById),
                'sourceSha256' => $plan['inputs']['sha256']['objectives'],
                'changeInstruction' => 'Ces mesures sont réservées à la validation. Toute reprise de recherche exige une nouvelle expérience et de nouvelles seeds réservées.',
            ],
            'scenarios' => array_map(function (string $id) use ($initial): array {
                $row = current(array_filter($initial['rows'], static fn (array $value): bool => $value['scenarioId'] === $id));
                return ['id' => $id, 'label' => $row['scenarioLabel']];
            }, $scenarioIds),
            'initial' => $initial,
            'finalists' => $presented,
            'defaultFinalistId' => $presented[0]['id'],
            'decision' => [
                'options' => ['retain-candidate', 'request-new-experiment'],
                'selectionEffect' => 'local-export-only',
                'resultAdaptiveSearchAllowed' => false,
            ],
            'browserContract' => [
                'simulationAllowed' => false,
                'objectiveInferenceAllowed' => false,
                'inputMutationAllowed' => false,
                'objectiveCount' => 32,
            ],
        ];
    }

    /** @param array<string, mixed> $candidate @param array<string, array<string, mixed>> $zoneById @return array<string, mixed> */
    private function candidate(array $candidate, array $zoneById, string $planId, string $planSha256): array
    {
        $report = $candidate['aggregate']['report'];
        $evaluation = $candidate['aggregate']['evaluation'];
        $entries = [];
        foreach ($evaluation['entries'] as $entry) {
            $entries[$entry['objectiveId']] = $entry;
        }
        $variation = [];
        foreach ($candidate['variationBetweenBatches'] as $entry) {
            $variation[$entry['objectiveId']] = $entry;
        }
        if (32 !== count($entries) || 32 !== count($variation)) {
            throw new \InvalidArgumentException('Every T33 candidate must expose 32 objectives and variations.');
        }

        $rows = [];
        foreach ($report['rows'] as $row) {
            $objectiveId = sprintf('%s-%s-tip-survivors', $row['scenarioId'], $row['side']);
            $entry = $entries[$objectiveId] ?? null;
            $zone = $zoneById[$objectiveId] ?? null;
            if (!is_array($entry) || !is_array($zone)) {
                throw new \InvalidArgumentException(sprintf('Missing T33 objective "%s".', $objectiveId));
            }
            if (($entry['target'] ?? null) !== ['center' => $zone['center'], 'radii' => $zone['radii']]
                || abs((float) $entry['observed']['x'] - (float) $row['candidate']['winRate']['value']) > 1e-12
                || abs((float) $entry['observed']['y'] - (float) $row['candidate']['metrics']['survivors']['value']) > 1e-12) {
                throw new \InvalidArgumentException(sprintf('T33 report, evaluation and objective document disagree on "%s".', $objectiveId));
            }
            $objective = [
                'id' => $objectiveId,
                'target' => $entry['target'],
                'state' => $entry['state'],
                'normalizedRadialDistance' => $entry['normalizedRadialDistance'],
                'excess' => $entry['normalizedBoundaryExcess'],
                'lossContribution' => $entry['lossContribution'],
                'weight' => $entry['objectiveWeight'],
                'variationBetweenBatches' => $variation[$objectiveId],
            ];
            $rows[] = [
                'objectiveId' => $objectiveId,
                'scenarioId' => $row['scenarioId'],
                'scenarioLabel' => $row['scenarioLabel'],
                'side' => $row['side'],
                'shape' => 'attacker' === $row['side'] ? 'circle' : 'diamond',
                'observation' => $this->observation($row['candidate']),
                'neutral' => $this->observation($row['baseline']),
                'objectives' => ['survivors' => $objective, 'economicValue' => $objective + ['aliasOf' => 'survivors'], 'structure' => null],
            ];
        }

        $variant = $report['candidate'];
        $summary = $this->summary($evaluation);
        $batchSummaries = array_map(fn (array $batch): array => [
            'batch' => $batch['batch'],
            'baseSeed' => $batch['baseSeed'],
            ...$this->summary($batch['evaluation']),
        ], $candidate['batches']);
        $provenance = [
            'planId' => $planId,
            'planSha256' => $planSha256,
            'candidateId' => $candidate['id'],
            'candidateVersion' => $candidate['version'],
            'rank' => $candidate['rank'],
            'parameterFingerprint' => $candidate['parameterFingerprint'],
            'status' => $candidate['status'],
        ];

        return [
            'kind' => $candidate['kind'],
            'rank' => $candidate['rank'],
            'id' => $variant['id'],
            'label' => $variant['label'],
            'version' => $variant['version'],
            'parameterFingerprint' => $candidate['parameterFingerprint'],
            'stabilityStatus' => $candidate['status'],
            'summary' => $summary,
            'batchSummaries' => $batchSummaries,
            'variationBetweenBatches' => $candidate['variationBetweenBatches'],
            'rows' => $rows,
            'source' => [
                'variant' => ['path' => $candidate['sourcePath'], 'sha256' => $candidate['sha256']],
                'evaluation' => ['path' => 'result.json#aggregate', 'sha256' => hash('sha256', $this->encode($evaluation))],
            ],
            'downloads' => [
                'variant' => $this->download('variant', $variant, $provenance),
                'evaluation' => $this->download('aggregate-evaluation', $evaluation, $provenance),
            ],
        ];
    }

    /** @param array<string, mixed> $evaluation @return array<string, mixed> */
    private function summary(array $evaluation): array
    {
        return [
            'continuousLoss' => $evaluation['continuousObjective']['value'],
            'objectivesSatisfied' => $evaluation['acceptance']['satisfied'],
            'objectiveCount' => $evaluation['acceptance']['total'],
            'worstObjectiveId' => $evaluation['continuousObjective']['worst']['objectiveId'],
            'worstExcess' => $evaluation['continuousObjective']['worst']['value'],
            'drawCount' => $evaluation['draws']['count'],
            'drawRate' => $evaluation['draws']['rate'],
            'strictControls' => $evaluation['strictControls'],
            'classificationLabel' => true === $evaluation['strictControls']['passed'] ? 'Contrôles stricts réussis' : 'Contrôles stricts non satisfaits',
        ];
    }

    /** @param array<string, mixed> $sample @return array<string, mixed> */
    private function observation(array $sample): array
    {
        return [
            'winRate' => $sample['winRate']['value'],
            'survivors' => $sample['metrics']['survivors']['value'],
            'economicValue' => $sample['metrics']['economicValue']['value'],
            'structure' => $sample['metrics']['structure']['value'],
            'wins' => $sample['wins'],
            'draws' => $sample['draws'],
            'iterations' => $sample['iterations'],
        ];
    }

    /** @param array<string, mixed> $artifact @param array<string, mixed> $provenance @return array<string, mixed> */
    private function download(string $kind, array $artifact, array $provenance): array
    {
        $document = ['schemaVersion' => 'waar-t33-provenanced-artifact/0.1', 'kind' => $kind, 'provenance' => $provenance, 'artifact' => $artifact];
        return ['filename' => sprintf('%s-%s-with-provenance.json', $provenance['candidateId'], $kind), 'json' => $this->encode($document)];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
