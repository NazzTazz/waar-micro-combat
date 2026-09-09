<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\CombatTieBreakPolicy;

final readonly class CanonicalMonotypeObjectiveEvaluator
{
    public const SCHEMA_VERSION = 'waar-monotype-objective-evaluation/0.1';
    public const OBJECTIVE_COUNT = 32;

    public function __construct(private AcceptanceZoneEvaluator $zoneEvaluator = new AcceptanceZoneEvaluator())
    {
    }

    /**
     * @param array<string, mixed> $microReport
     * @param array<string, mixed> $objectiveDocument
     * @return array<string, mixed>
     */
    public function evaluate(array $microReport, array $objectiveDocument): array
    {
        $expectedOverlay = (new MonotypeObjectiveOverlayBuilder())->build($microReport);
        $expectedDocument = $expectedOverlay['zonesDocument'];
        $zones = $this->validateDocument($objectiveDocument, $expectedDocument);
        $rows = $this->candidateRows($microReport);

        $entries = [];
        $satisfied = 0;
        foreach ($zones as $zone) {
            $row = $rows[$zone['scenarioId']."\0".$zone['side']];
            $x = $row['candidate']['winRate']['value'];
            $y = $row['candidate']['metrics']['survivors']['value'];
            $state = $this->zoneEvaluator->evaluate($x, $y, $zone);
            if ('inside' === $state) {
                ++$satisfied;
            }
            $entries[] = [
                'objectiveId' => $zone['id'],
                'scenarioId' => $zone['scenarioId'],
                'side' => $zone['side'],
                'objectiveMetric' => $zone['yMetric'],
                'observed' => ['x' => $x, 'y' => $y],
                'target' => ['center' => $zone['center'], 'radii' => $zone['radii']],
                'normalizedSquaredDistance' => $this->zoneEvaluator->normalizedSquaredDistance($x, $y, $zone),
                'state' => $state,
                'scoreContribution' => 'inside' === $state ? 1 : 0,
            ];
        }

        $draws = 0;
        $scenariosWithDraws = 0;
        foreach ($microReport['rows'] as $row) {
            if ('attacker' !== $row['side']) {
                continue;
            }
            $scenarioDraws = $row['candidate']['draws'];
            $draws += $scenarioDraws;
            if ($scenarioDraws > 0) {
                ++$scenariosWithDraws;
            }
        }
        $iterations = $microReport['experiment']['iterations'];
        $scenarioCount = $microReport['experiment']['scenarioCount'];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'experimentId' => $microReport['experiment']['id'],
            'candidate' => [
                'id' => $microReport['candidate']['id'],
                'version' => $microReport['candidate']['version'],
                'tieBreakPolicy' => $microReport['candidate']['tieBreakPolicy'] ?? CombatTieBreakPolicy::Draw->value,
            ],
            'objectiveContract' => [
                'generationId' => MonotypeObjectiveOverlayBuilder::GENERATOR_ID,
                'metric' => 'survivors',
                'count' => self::OBJECTIVE_COUNT,
                'oneContributionPerObjective' => true,
            ],
            'score' => [
                'kind' => 'equal-objective-satisfaction',
                'satisfied' => $satisfied,
                'total' => self::OBJECTIVE_COUNT,
                'rate' => $satisfied / self::OBJECTIVE_COUNT,
            ],
            'strictControls' => [
                'allObjectivesSatisfied' => self::OBJECTIVE_COUNT === $satisfied,
                'noDraws' => 0 === $draws,
                'passed' => self::OBJECTIVE_COUNT === $satisfied && 0 === $draws,
            ],
            'draws' => [
                'count' => $draws,
                'combatCount' => $iterations * $scenarioCount,
                'rate' => $draws / ($iterations * $scenarioCount),
                'scenarioCount' => $scenariosWithDraws,
            ],
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $expectedDocument
     * @return list<array<string, mixed>>
     */
    private function validateDocument(array $document, array $expectedDocument): array
    {
        foreach (['schemaVersion', 'experimentId', 'corpusFingerprint', 'comparisonProfileId', 'valuationId'] as $field) {
            if (($document[$field] ?? null) !== ($expectedDocument[$field] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Canonical objective %s does not match the monotype experiment.', $field));
            }
        }
        if (MonotypeObjectiveOverlayBuilder::GENERATOR_ID !== ($document['generation']['id'] ?? null)) {
            throw new \InvalidArgumentException('The objective document is not the canonical monotype-survivors generation.');
        }
        $zones = $document['zones'] ?? null;
        if (!is_array($zones) || !array_is_list($zones) || self::OBJECTIVE_COUNT !== count($zones)) {
            throw new \InvalidArgumentException('The search input must contain exactly 32 canonical objectives.');
        }

        $expectedById = [];
        foreach ($expectedDocument['zones'] as $zone) {
            $expectedById[$zone['id']] = $zone;
        }
        $seen = [];
        $pairs = [];
        foreach ($zones as $zone) {
            if (!is_array($zone) || array_is_list($zone)) {
                throw new \InvalidArgumentException('Every canonical objective must be an object.');
            }
            $id = $zone['id'] ?? null;
            if (!is_string($id) || isset($seen[$id]) || !isset($expectedById[$id])) {
                throw new \InvalidArgumentException('Canonical objective ids must be known and unique.');
            }
            $seen[$id] = true;
            $expected = $expectedById[$id];
            foreach (['scenarioId', 'side', 'endpoint', 'xMetric', 'yMetric', 'shape'] as $field) {
                if (($zone[$field] ?? null) !== $expected[$field]) {
                    throw new \InvalidArgumentException(sprintf('Canonical objective %s has an invalid %s.', $id, $field));
                }
            }
            if ('survivors' !== $zone['yMetric'] || true !== ($zone['enabled'] ?? null) || 'confirmed' !== ($zone['approval'] ?? null)) {
                throw new \InvalidArgumentException('Search requires the 32 enabled and confirmed survivors objectives.');
            }
            $this->validateGeometry($zone, $id);
            if (($zone['source']['referencePointId'] ?? null) !== $expected['source']['referencePointId']) {
                throw new \InvalidArgumentException(sprintf('Canonical objective %s has incompatible provenance.', $id));
            }
            $pairs[$zone['scenarioId']][$zone['side']] = $zone;
        }
        if (count($seen) !== count($expectedById)) {
            throw new \InvalidArgumentException('The canonical objective set is incomplete.');
        }
        foreach ($pairs as $pair) {
            $attacker = $pair['attacker'] ?? null;
            $defender = $pair['defender'] ?? null;
            if (null === $attacker || null === $defender
                || abs($attacker['center']['x'] + $defender['center']['x'] - 1.0) > AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE
                || abs($attacker['radii']['x'] - $defender['radii']['x']) > AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE) {
                throw new \InvalidArgumentException('Every monotype pair must use complementary win rates and one shared X radius.');
            }
        }

        return $zones;
    }

    /** @param array<string, mixed> $zone */
    private function validateGeometry(array $zone, string $id): void
    {
        foreach ([
            $zone['center']['x'] ?? null,
            $zone['center']['y'] ?? null,
            $zone['radii']['x'] ?? null,
            $zone['radii']['y'] ?? null,
        ] as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException(sprintf('Canonical objective %s has invalid geometry.', $id));
            }
        }
        if ($zone['center']['x'] < 0 || $zone['center']['x'] > 1
            || $zone['center']['y'] < 0 || $zone['center']['y'] > 1
            || $zone['radii']['x'] < 0.005 || $zone['radii']['x'] > 1
            || $zone['radii']['y'] < 0.005 || $zone['radii']['y'] > 1) {
            throw new \InvalidArgumentException(sprintf('Canonical objective %s has geometry outside the accepted domain.', $id));
        }
    }

    /** @param array<string, mixed> $microReport @return array<string, array<string, mixed>> */
    private function candidateRows(array $microReport): array
    {
        $rows = [];
        foreach ($microReport['rows'] ?? [] as $row) {
            $key = ($row['scenarioId'] ?? '')."\0".($row['side'] ?? '');
            if (isset($rows[$key])) {
                throw new \InvalidArgumentException('The monotype report contains duplicate rows.');
            }
            $rows[$key] = $row;
        }
        if (self::OBJECTIVE_COUNT !== count($rows)) {
            throw new \InvalidArgumentException('The monotype report must expose exactly 32 scenario-side rows.');
        }

        return $rows;
    }
}
