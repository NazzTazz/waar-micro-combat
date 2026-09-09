<?php

namespace Waar\MicroCombat\Experiment;

final class FinalistComparisonBuilder
{
    public const SCHEMA_VERSION = 'waar-monotype-finalist-comparison/0.1';

    /** @return array<string, mixed> */
    public function buildFromDirectory(string $runDirectory, string $initialMicroReportPath): array
    {
        $runDirectory = rtrim($runDirectory, '/\\');
        $result = $this->readJson($runDirectory.'/search-result.json');
        $objectives = $this->readJson($runDirectory.'/objectives.json');
        $experiment = $this->readJson($runDirectory.'/experiment.json');
        $initialVariant = $this->readJson($runDirectory.'/candidate-initial.json');
        $initialEvaluation = $this->readJson($runDirectory.'/evaluation-initial.json');
        $initialReport = $this->readJson($initialMicroReportPath);

        $this->expect('waar-monotype-search-result/0.1' === ($result['schemaVersion'] ?? null), 'Unsupported T31 search result schema.');
        $this->expect('waar-acceptance-zones/0.2' === ($objectives['schemaVersion'] ?? null), 'Unsupported objective document schema.');
        $this->expect(32 === count($objectives['zones'] ?? []), 'T32 requires the 32 canonical monotype objectives.');
        $this->expect(($result['initial']['candidateId'] ?? null) === ($initialVariant['id'] ?? null), 'The initial variant does not match the T31 result.');
        $this->expect(($initialEvaluation['candidate']['id'] ?? null) === ($initialVariant['id'] ?? null), 'The initial evaluation does not match the initial variant.');
        $this->expect(($initialReport['candidate']['id'] ?? null) === ($initialVariant['id'] ?? null), 'The initial micro report does not match the T31 initial candidate.');
        $this->expect(($initialReport['experiment']['id'] ?? null) === ($initialEvaluation['experimentId'] ?? null), 'The initial micro report and evaluation describe different experiments.');
        $this->expect(($experiment['candidate'] ?? null) === $initialVariant, 'The T31 experiment does not contain the declared initial variant.');
        $this->expect(($initialReport['candidate'] ?? null) === $initialVariant, 'The initial micro report does not contain the complete T31 initial variant.');
        $this->expect(($initialReport['baseline'] ?? null) === ($experiment['baseline'] ?? null), 'The initial micro report does not contain the T31 neutral baseline.');
        $this->expect(($initialReport['experiment']['iterations'] ?? null) === ($experiment['iterations'] ?? null) && ($initialReport['experiment']['baseSeed'] ?? null) === ($experiment['baseSeed'] ?? null), 'The initial micro report does not use the T31 sampling contract.');
        $this->verifyHash($runDirectory.'/candidate-initial.json', $result['inputSha256']['candidateInitial'] ?? null, 'initial variant');
        $this->verifyHash($runDirectory.'/objectives.json', $result['inputSha256']['objectives'] ?? null, 'objective document');

        $zoneById = [];
        foreach ($objectives['zones'] as $zone) {
            $id = $zone['id'] ?? null;
            $this->expect(is_string($id) && !isset($zoneById[$id]), 'Objective identifiers must be unique.');
            $this->expect('survivors' === ($zone['yMetric'] ?? null) && true === ($zone['enabled'] ?? null) && 'confirmed' === ($zone['approval'] ?? null), 'T32 requires enabled, confirmed survivor objectives.');
            $zoneById[$id] = $zone;
        }

        $initial = $this->candidate(
            $initialVariant,
            $initialEvaluation,
            $initialReport,
            $zoneById,
            ['kind' => 'initial', 'rank' => null, 'parameterFingerprint' => $result['initial']['parameterFingerprint'] ?? null, 'planId' => $result['planId'] ?? null],
            [
                'variant' => ['path' => 'candidate-initial.json', 'sha256' => hash_file('sha256', $runDirectory.'/candidate-initial.json')],
                'evaluation' => ['path' => 'evaluation-initial.json', 'sha256' => hash_file('sha256', $runDirectory.'/evaluation-initial.json')],
                'microReport' => ['path' => $initialMicroReportPath, 'sha256' => hash_file('sha256', $initialMicroReportPath)],
            ],
        );

        $finalists = [];
        foreach ($result['finalists'] ?? [] as $entry) {
            $paths = $entry['artifacts'] ?? [];
            foreach (['variant', 'evaluation', 'microReport'] as $kind) {
                $this->expect(is_string($paths[$kind] ?? null), sprintf('Finalist %s has no %s artifact.', $entry['candidateId'] ?? '?', $kind));
                $this->expect(!str_contains(str_replace('\\', '/', $paths[$kind]), '../'), 'Finalist artifact paths must remain inside the T31 run.');
                $this->verifyHash($runDirectory.'/'.$paths[$kind], $entry['sha256'][$kind] ?? null, $kind);
            }
            $variant = $this->readJson($runDirectory.'/'.$paths['variant']);
            $evaluation = $this->readJson($runDirectory.'/'.$paths['evaluation']);
            $report = $this->readJson($runDirectory.'/'.$paths['microReport']);
            $this->expect(($entry['candidateId'] ?? null) === ($variant['id'] ?? null), 'A finalist variant does not match its T31 declaration.');
            $this->expect(($entry['candidateId'] ?? null) === ($evaluation['candidate']['id'] ?? null), 'A finalist evaluation does not match its T31 declaration.');
            $this->expect(($entry['candidateId'] ?? null) === ($report['candidate']['id'] ?? null), 'A finalist micro report does not match its T31 declaration.');
            $this->expect(true === ($entry['authoritativeReplayMatched'] ?? false), 'T32 refuses a finalist whose authoritative replay did not match.');

            $source = [];
            foreach (['variant', 'evaluation', 'microReport'] as $kind) {
                $source[$kind] = ['path' => $paths[$kind], 'sha256' => strtolower((string) $entry['sha256'][$kind])];
            }
            $finalists[] = $this->candidate($variant, $evaluation, $report, $zoneById, [
                'kind' => 'finalist',
                'rank' => $entry['rank'] ?? null,
                'sequence' => $entry['sequence'] ?? null,
                'phase' => $entry['phase'] ?? null,
                'parameterFingerprint' => $entry['parameterFingerprint'] ?? null,
                'planId' => $result['planId'] ?? null,
            ], $source);
        }
        $this->expect([] !== $finalists, 'T32 requires at least one finalist.');
        usort($finalists, static fn (array $left, array $right): int => ($left['rank'] ?? PHP_INT_MAX) <=> ($right['rank'] ?? PHP_INT_MAX));

        $scenarioIds = array_values(array_unique(array_column($initial['rows'], 'scenarioId')));
        $this->expect(16 === count($scenarioIds) && 32 === count($initial['rows']), 'The initial report must contain 16 matchups and both sides.');
        foreach ($finalists as $candidate) {
            $this->expect($scenarioIds === array_values(array_unique(array_column($candidate['rows'], 'scenarioId'))), 'All finalists must preserve the ordered T31 corpus.');
            $this->expect(32 === count($candidate['rows']), 'Every finalist must expose exactly 32 observations.');
        }

        $evaluated = (int) ($result['counts']['evaluatedCandidates'] ?? 0);
        $budget = (int) ($result['counts']['evaluationBudget'] ?? 0);
        $complete = 'completed' === ($result['state'] ?? null) && $evaluated === $budget;
        $runState = $this->runState((string) ($result['state'] ?? 'unknown'), (string) ($result['outcome'] ?? 'unknown'), $complete, $evaluated, $budget);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'title' => 'T32 — comparaison visuelle des finalistes',
            'run' => [
                'planId' => $result['planId'] ?? null,
                'state' => $result['state'] ?? null,
                'outcome' => $result['outcome'] ?? null,
                'complete' => $complete,
                'statusLabel' => $runState['label'],
                'statusDetail' => $runState['detail'],
                'counts' => $result['counts'] ?? [],
                'execution' => $result['execution'] ?? [],
                'source' => [
                    'directory' => $runDirectory,
                    'searchResultSha256' => hash_file('sha256', $runDirectory.'/search-result.json'),
                    'objectivesSha256' => hash_file('sha256', $runDirectory.'/objectives.json'),
                    'experimentSha256' => hash_file('sha256', $runDirectory.'/experiment.json'),
                ],
            ],
            'experiment' => [
                'id' => $initialReport['experiment']['id'],
                'label' => $initialReport['experiment']['label'],
                'iterations' => $initialReport['experiment']['iterations'],
                'scenarioCount' => count($scenarioIds),
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
                'label' => $objectives['generation']['label'] ?? null,
                'readOnly' => true,
                'count' => count($objectives['zones']),
                'sourceSha256' => hash_file('sha256', $runDirectory.'/objectives.json'),
                'changeInstruction' => 'Pour changer le design PO, utiliser l’éditeur existant, exporter un nouveau document et lancer une expérience distincte.',
            ],
            'scenarios' => array_map(function (string $scenarioId) use ($initial): array {
                $row = current(array_filter($initial['rows'], static fn (array $candidateRow): bool => $candidateRow['scenarioId'] === $scenarioId));

                return ['id' => $scenarioId, 'label' => $row['scenarioLabel']];
            }, $scenarioIds),
            'initial' => $initial,
            'finalists' => $finalists,
            'defaultFinalistId' => $finalists[0]['id'],
            'browserContract' => [
                'simulationAllowed' => false,
                'objectiveInferenceAllowed' => false,
                'inputMutationAllowed' => false,
                'objectiveCount' => 32,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @param array<string, mixed> $evaluation
     * @param array<string, mixed> $report
     * @param array<string, array<string, mixed>> $zoneById
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function candidate(array $variant, array $evaluation, array $report, array $zoneById, array $identity, array $source): array
    {
        $entries = [];
        $this->expect(32 === count($evaluation['entries'] ?? []), 'Every candidate evaluation must contain exactly 32 objective entries.');
        foreach ($evaluation['entries'] ?? [] as $entry) {
            $entries[$entry['objectiveId']] = $entry;
        }
        $this->expect(32 === count($entries), 'Every candidate evaluation must contain 32 unique objectives.');

        $rows = [];
        $rowObjectiveIds = [];
        foreach ($report['rows'] ?? [] as $row) {
            $objectiveId = sprintf('%s-%s-tip-survivors', $row['scenarioId'], $row['side']);
            $this->expect(isset($entries[$objectiveId], $zoneById[$objectiveId]), sprintf('Missing objective %s.', $objectiveId));
            $this->expect(!isset($rowObjectiveIds[$objectiveId]), sprintf('Duplicate observation for objective %s.', $objectiveId));
            $rowObjectiveIds[$objectiveId] = true;
            $entry = $entries[$objectiveId];
            $this->expect($entry['target'] === ['center' => $zoneById[$objectiveId]['center'], 'radii' => $zoneById[$objectiveId]['radii']], 'The evaluation target does not match the frozen objective document.');
            $this->expect(abs((float) $row['candidate']['winRate']['value'] - (float) $entry['observed']['x']) < 1e-12, 'The evaluation and micro report disagree on win rate.');
            $this->expect(abs((float) $row['candidate']['metrics']['survivors']['value'] - (float) $entry['observed']['y']) < 1e-12, 'The evaluation and micro report disagree on survivors.');
            $this->expect(abs((float) $row['candidate']['metrics']['survivors']['value'] - (float) $row['candidate']['metrics']['economicValue']['value']) < 1e-12, 'T32 only aliases economic objectives for equal-cost monotypes.');
            $this->expect(abs((float) $row['baseline']['metrics']['survivors']['value'] - (float) $row['baseline']['metrics']['economicValue']['value']) < 1e-12, 'The neutral comparison must preserve the monotype economic alias.');

            $objective = [
                'id' => $objectiveId,
                'target' => $entry['target'],
                'state' => $entry['state'],
                'normalizedRadialDistance' => $entry['normalizedRadialDistance'],
                'excess' => $entry['normalizedBoundaryExcess'],
                'lossContribution' => $entry['lossContribution'],
                'weight' => $entry['objectiveWeight'],
            ];
            $rows[] = [
                'objectiveId' => $objectiveId,
                'scenarioId' => $row['scenarioId'],
                'scenarioLabel' => $row['scenarioLabel'],
                'side' => $row['side'],
                'shape' => 'attacker' === $row['side'] ? 'circle' : 'diamond',
                'observation' => $this->observation($row['candidate']),
                'neutral' => $this->observation($row['baseline']),
                'objectives' => [
                    'survivors' => $objective,
                    'economicValue' => $objective + ['aliasOf' => 'survivors'],
                    'structure' => null,
                ],
            ];
        }

        $summary = [
            'continuousLoss' => $evaluation['continuousObjective']['value'],
            'objectivesSatisfied' => $evaluation['acceptance']['satisfied'],
            'objectiveCount' => $evaluation['acceptance']['total'],
            'worstObjectiveId' => $evaluation['continuousObjective']['worst']['objectiveId'],
            'worstExcess' => $evaluation['continuousObjective']['worst']['value'],
            'drawCount' => $evaluation['draws']['count'],
            'drawRate' => $evaluation['draws']['rate'],
            'strictControls' => $evaluation['strictControls'],
            'classificationLabel' => true === $evaluation['strictControls']['passed'] ? 'Contrôles stricts réussis' : 'Exploratoire — contrôles stricts non satisfaits',
        ];
        $provenance = [
            'planId' => $identity['planId'],
            'candidateId' => $variant['id'],
            'candidateVersion' => $variant['version'],
            'rank' => $identity['rank'],
            'parameterFingerprint' => $identity['parameterFingerprint'],
            'source' => $source,
        ];

        return $identity + [
            'id' => $variant['id'],
            'label' => $variant['label'],
            'version' => $variant['version'],
            'summary' => $summary,
            'rows' => $rows,
            'source' => $source,
            'downloads' => [
                'variant' => $this->download('variant', $variant, $provenance),
                'evaluation' => $this->download('evaluation', $evaluation, $provenance),
            ],
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
        $document = [
            'schemaVersion' => 'waar-t32-provenanced-artifact/0.1',
            'kind' => $kind,
            'provenance' => $provenance,
            'artifact' => $artifact,
        ];

        return [
            'filename' => sprintf('%s-%s-with-provenance.json', $artifact['candidate']['id'] ?? $artifact['id'], $kind),
            'json' => json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        ];
    }

    /** @return array{label: string, detail: string} */
    private function runState(string $state, string $outcome, bool $complete, int $evaluated, int $budget): array
    {
        if (!$complete) {
            return ['label' => 'Run partiel', 'detail' => sprintf('%d/%d candidats évalués · état %s · résultat %s', $evaluated, $budget, $state, $outcome)];
        }
        if ('no-strict-candidate-found-within-budget' === $outcome) {
            return ['label' => 'Recherche achevée — aucun candidat strict dans ce budget', 'detail' => sprintf('%d/%d candidats évalués', $evaluated, $budget)];
        }

        return ['label' => 'Recherche achevée', 'detail' => sprintf('%d/%d candidats évalués · résultat %s', $evaluated, $budget, $outcome)];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(sprintf('JSON root in "%s" must be an object.', $path));
        }

        return $decoded;
    }

    private function verifyHash(string $path, mixed $expected, string $label): void
    {
        $this->expect(is_string($expected) && hash_equals(strtolower($expected), hash_file('sha256', $path)), sprintf('The %s SHA-256 does not match T31.', $label));
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
