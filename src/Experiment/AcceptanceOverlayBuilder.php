<?php

namespace Waar\MicroCombat\Experiment;

final readonly class AcceptanceOverlayBuilder
{
    public const SCHEMA_VERSION = 'waar-acceptance-overlay/0.1';
    public const ZONES_SCHEMA_VERSION = 'waar-acceptance-zones/0.2';
    public const GENERATOR_ID = 'legacy-centered-draft-ellipses-v1';
    public const RADIUS_X = 0.05;
    public const RADIUS_Y = 0.10;

    private const LEGACY_METRICS = [
        'survivors' => 'operationalSurvivorsRatio',
        'economicValue' => 'operationalEconomicValueRatio',
    ];

    public function __construct(private AcceptanceZoneEvaluator $evaluator = new AcceptanceZoneEvaluator())
    {
    }

    /** @param array<string, mixed> $microReport @param array<string, mixed> $legacyReference @return array<string, mixed> */
    public function build(array $microReport, array $legacyReference): array
    {
        if ('waar-micro-wind-tunnel-report/0.1' !== ($microReport['schemaVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported micro report schema.');
        }
        if ('waar-legacy-reference/0.1' !== ($legacyReference['schemaVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported Legacy reference schema.');
        }
        $experimentId = $microReport['experiment']['id'] ?? null;
        if (!is_string($experimentId) || $experimentId !== ($legacyReference['corpus']['experimentId'] ?? null)) {
            throw new \InvalidArgumentException('Micro report and Legacy reference use different experiments.');
        }
        if (($microReport['scenarios'] ?? null) !== ($legacyReference['corpus']['scenarios'] ?? null)) {
            throw new \InvalidArgumentException('Micro report and Legacy reference use different corpus definitions.');
        }
        if (($microReport['experiment']['iterations'] ?? null) !== ($legacyReference['sampling']['repetitions'] ?? null)
            || ($microReport['experiment']['baseSeed'] ?? null) !== ($legacyReference['sampling']['baseSeed'] ?? null)) {
            throw new \InvalidArgumentException('Micro report and Legacy reference use different sampling inputs.');
        }

        $valuation = $legacyReference['comparisonProfile']['valuation'] ?? null;
        if (!is_array($valuation)) {
            throw new \InvalidArgumentException('Legacy comparison valuation is missing.');
        }
        foreach (['baseline', 'candidate'] as $variant) {
            foreach ($valuation as $unit => $cost) {
                if ($cost !== ($microReport[$variant]['units'][$unit]['cost'] ?? null)) {
                    throw new \InvalidArgumentException(sprintf('The %s micro costs do not match the common valuation.', $variant));
                }
            }
        }

        $legacyRows = [];
        foreach ($legacyReference['rows'] ?? [] as $row) {
            $legacyRows[$row['scenarioId']."\0".$row['side']] = $row;
        }

        $rows = [];
        $zones = [];
        $zoneStates = [];
        foreach ($microReport['rows'] ?? [] as $microRow) {
            $key = $microRow['scenarioId']."\0".$microRow['side'];
            $legacyRow = $legacyRows[$key] ?? null;
            if (!is_array($legacyRow)) {
                throw new \InvalidArgumentException(sprintf('Missing Legacy row for %s/%s.', $microRow['scenarioId'], $microRow['side']));
            }
            $legacyCoordinates = [
                'x' => $legacyRow['coordinates']['x'],
                'y' => [
                    'survivors' => $legacyRow['coordinates']['y']['operationalSurvivorsRatio'],
                    'structure' => null,
                    'economicValue' => $legacyRow['coordinates']['y']['operationalEconomicValueRatio'],
                ],
            ];
            $rowZones = [];
            foreach (self::LEGACY_METRICS as $microMetric => $profileMetric) {
                foreach (['base' => 'from', 'tip' => 'to'] as $endpoint => $coordinateKey) {
                    $zone = $this->zone($microRow, $legacyRow, $legacyReference['reference']['id'], $microMetric, $profileMetric, $endpoint);
                    $zoneStates[$zone['id']] = $this->evaluator->evaluate(
                        (float) $microRow['vector']['x'][$coordinateKey],
                        (float) $microRow['vector']['y'][$microMetric][$coordinateKey],
                        $zone,
                    );
                    $zones[] = $zone;
                    $rowZones[$microMetric][$endpoint] = $zone['id'];
                }
            }
            $rows[] = [
                'scenarioId' => $microRow['scenarioId'],
                'scenarioLabel' => $microRow['scenarioLabel'],
                'side' => $microRow['side'],
                'focus' => $microRow['focus'],
                'army' => $microRow['army'],
                'micro' => $microRow,
                'legacy' => [
                    'wins' => $legacyRow['wins'],
                    'draws' => $legacyRow['draws'],
                    'repetitions' => $legacyRow['repetitions'],
                    'coordinates' => $legacyCoordinates,
                    'initial' => $legacyRow['initial'],
                ],
                'zoneIds' => $rowZones,
            ];
        }
        if (count($rows) !== count($legacyRows)) {
            throw new \InvalidArgumentException('Micro report and Legacy reference do not contain the same scenario/side rows.');
        }

        $zonesDocument = [
            'schemaVersion' => self::ZONES_SCHEMA_VERSION,
            'experimentId' => $experimentId,
            'corpusFingerprint' => $legacyReference['corpus']['fingerprint'],
            'comparisonProfileId' => $legacyReference['comparisonProfile']['id'],
            'valuationId' => $legacyReference['comparisonProfile']['valuationId'],
            'generation' => [
                'id' => self::GENERATOR_ID,
                'label' => 'Tolérances proposées — à confirmer',
                'radiusX' => self::RADIUS_X,
                'radiusY' => self::RADIUS_Y,
                'readOnly' => false,
            ],
            'zones' => $zones,
        ];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'experiment' => $microReport['experiment'],
            'axes' => $microReport['axes'],
            'baseline' => $microReport['baseline'],
            'candidate' => $microReport['candidate'],
            'comparisonProfile' => $legacyReference['comparisonProfile'],
            'legacyReference' => [
                'id' => $legacyReference['reference']['id'],
                'rulesetVersion' => $legacyReference['reference']['rulesetVersion'],
                'sourceFingerprint' => $legacyReference['reference']['sourceFingerprint'],
                'corpusFingerprint' => $legacyReference['corpus']['fingerprint'],
                'sampling' => $legacyReference['sampling'],
            ],
            'rows' => $rows,
            'zonesDocument' => $zonesDocument,
            'zoneStates' => $zoneStates,
        ];
    }

    /** @param array<string, mixed> $microRow @param array<string, mixed> $legacyRow @return array<string, mixed> */
    private function zone(array $microRow, array $legacyRow, string $referenceId, string $microMetric, string $profileMetric, string $endpoint): array
    {
        $center = [
            'x' => $legacyRow['coordinates']['x'],
            'y' => $legacyRow['coordinates']['y'][$profileMetric],
        ];

        return [
            'id' => implode('-', [$microRow['scenarioId'], $microRow['side'], $endpoint, $microMetric]),
            'scenarioId' => $microRow['scenarioId'],
            'side' => $microRow['side'],
            'endpoint' => $endpoint,
            'xMetric' => 'winRate',
            'yMetric' => $microMetric,
            'shape' => 'ellipse',
            'center' => $center,
            'radii' => ['x' => self::RADIUS_X, 'y' => self::RADIUS_Y],
            'enabled' => true,
            'approval' => 'draft',
            'source' => [
                'kind' => 'legacy',
                'referenceId' => $referenceId,
                'referencePointId' => $legacyRow['scenarioId'].'/'.$legacyRow['side'],
                'originalCenter' => $center,
                'modifiedManually' => false,
            ],
        ];
    }
}
