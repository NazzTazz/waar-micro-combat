<?php

namespace Waar\MicroCombat\Experiment;

final readonly class MonotypeObjectiveOverlayBuilder
{
    public const SCHEMA_VERSION = 'waar-monotype-objective-overlay/0.1';
    public const PROFILE_ID = 'monotype-equal-cost-v1';
    public const GENERATOR_ID = 'canonical-monotype-survivors-v1';
    public const RADIUS_X = 0.05;
    public const RADIUS_Y = 0.10;

    public function __construct(private AcceptanceZoneEvaluator $evaluator = new AcceptanceZoneEvaluator())
    {
    }

    /** @param array<string, mixed> $microReport @return array<string, mixed> */
    public function build(array $microReport): array
    {
        if ('waar-micro-wind-tunnel-report/0.1' !== ($microReport['schemaVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported micro report schema.');
        }
        $scenarios = $microReport['scenarios'] ?? null;
        if (!is_array($scenarios) || 16 !== count($scenarios)) {
            throw new \InvalidArgumentException('The monotype surface must contain exactly sixteen ordered scenarios.');
        }
        $valuation = $this->valuation($microReport);
        [$commonBudget, $scenarioBudgets] = $this->validateSurface($scenarios, $valuation);
        $corpusFingerprint = hash('sha256', json_encode(
            ['profile' => self::PROFILE_ID, 'valuation' => $valuation, 'scenarios' => $scenarios],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $referenceId = ($microReport['candidate']['id'] ?? '').'@'.($microReport['candidate']['version'] ?? '');
        if ('@' === $referenceId) {
            throw new \InvalidArgumentException('The candidate reference is missing.');
        }

        $rows = [];
        $zones = [];
        $zoneStates = [];
        $attackerWinRates = [];
        foreach ($microReport['rows'] ?? [] as $row) {
            if ('attacker' === $row['side']) {
                $attackerWinRates[$row['scenarioId']] = $row['vector']['x']['to'];
            }
        }
        foreach ($microReport['rows'] ?? [] as $row) {
            $rowZones = [];
            foreach (['survivors'] as $metric) {
                $zone = $this->zone($row, $metric, $referenceId);
                if ('defender' === $row['side']) {
                    $attackerWinRate = $attackerWinRates[$row['scenarioId']] ?? throw new \InvalidArgumentException('Missing attacker win rate for monotype pair.');
                    $zone['center']['x'] = 1.0 - $attackerWinRate;
                }
                $zones[] = $zone;
                $rowZones[$metric]['tip'] = $zone['id'];
                $rowZones['economicValue']['tip'] = $zone['id'];
                $zoneStates[$zone['id']] = $this->evaluator->evaluate(
                    (float) $row['vector']['x']['to'],
                    null === $row['vector']['y'][$metric]['to'] ? null : (float) $row['vector']['y'][$metric]['to'],
                    $zone,
                );
            }
            $rows[] = [
                'scenarioId' => $row['scenarioId'],
                'scenarioLabel' => $row['scenarioLabel'],
                'side' => $row['side'],
                'focus' => $row['focus'],
                'army' => $row['army'],
                'micro' => $row,
                'legacy' => [
                    'wins' => null,
                    'draws' => null,
                    'repetitions' => null,
                    'coordinates' => ['x' => null, 'y' => ['survivors' => null, 'structure' => null, 'economicValue' => null]],
                    'initial' => null,
                ],
                'zoneIds' => $rowZones,
            ];
        }
        if (32 !== count($rows)) {
            throw new \InvalidArgumentException('The monotype report must contain both sides of every scenario.');
        }

        $zonesDocument = [
            'schemaVersion' => AcceptanceOverlayBuilder::ZONES_SCHEMA_VERSION,
            'experimentId' => $microReport['experiment']['id'],
            'corpusFingerprint' => $corpusFingerprint,
            'comparisonProfileId' => self::PROFILE_ID,
            'valuationId' => 't24-common-valuation-v1',
            'generation' => [
                'id' => self::GENERATOR_ID,
                'label' => 'Objectifs monotypes sans nul — taux complémentaires, à définir',
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
            'comparisonProfile' => [
                'id' => self::PROFILE_ID,
                'valuationId' => 't24-common-valuation-v1',
                'valuation' => $valuation,
                'commonBudget' => $commonBudget,
            ],
            'legacyReference' => [
                'available' => false,
                'id' => $referenceId,
                'rulesetVersion' => $microReport['candidate']['version'],
                'sourceFingerprint' => hash('sha256', json_encode($microReport['candidate'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'corpusFingerprint' => $corpusFingerprint,
                'sampling' => ['baseSeed' => $microReport['experiment']['baseSeed'], 'repetitions' => $microReport['experiment']['iterations']],
            ],
            'objectiveReference' => [
                'kind' => 'observation',
                'id' => $referenceId,
                'label' => $microReport['candidate']['label'],
            ],
            'designSurface' => [
                'kind' => 'ordered-monotype-matrix',
                'unitOrder' => array_keys($valuation),
                'scenarioCount' => count($scenarios),
                'commonBudget' => $commonBudget,
                'scenarioBudgets' => $scenarioBudgets,
                't24ContributesConstraints' => false,
            ],
            'ui' => [
                'title' => 'Soufflerie Waar — objectifs monotypes',
                'referenceAvailable' => false,
                'defaultEndpoint' => 'tip',
                'pairView' => true,
                'complementaryWinRates' => true,
                'canonicalMonotypeObjectives' => true,
                'editableEndpoints' => ['tip'],
                'zoneLabel' => 'Objectif monotype',
                'colorBy' => 'attackerType',
                'colorLabel' => 'Autres paires : couleur du type attaquant',
                'noReferenceMessage' => '32 objectifs : un par camp et par paire. Survivants et valeur économique représentent le même objectif monotype. Structure : diagnostic seul. Victoires attaquant + défenseur = 100 % ; le prototype peut encore produire des nuls.',
            ],
            'rows' => $rows,
            'zonesDocument' => $zonesDocument,
            'zoneStates' => $zoneStates,
        ];
    }

    /** @param array<string, mixed> $report @return array<string, int> */
    private function valuation(array $report): array
    {
        $baseline = $report['baseline']['units'] ?? null;
        $candidate = $report['candidate']['units'] ?? null;
        if (!is_array($baseline) || !is_array($candidate) || array_keys($baseline) !== array_keys($candidate)) {
            throw new \InvalidArgumentException('Baseline and candidate unit catalogs differ.');
        }
        $valuation = [];
        foreach ($baseline as $unit => $definition) {
            $cost = $definition['cost'] ?? null;
            if (!is_int($cost) || $cost <= 0 || $cost !== ($candidate[$unit]['cost'] ?? null)) {
                throw new \InvalidArgumentException('Baseline and candidate must use the same positive integer costs.');
            }
            $valuation[$unit] = $cost;
        }

        return $valuation;
    }

    /** @param list<array<string, mixed>> $scenarios @param array<string, int> $valuation @return array{int, list<array<string, mixed>>} */
    private function validateSurface(array $scenarios, array $valuation): array
    {
        $pairs = [];
        $budgets = [];
        $commonBudget = null;
        foreach ($scenarios as $scenario) {
            [$attackerUnit, $attackerCount, $attackerBudget] = $this->monotype($scenario['attacker'] ?? null, $valuation);
            [$defenderUnit, $defenderCount, $defenderBudget] = $this->monotype($scenario['defender'] ?? null, $valuation);
            if ($attackerBudget !== $defenderBudget || (null !== $commonBudget && $attackerBudget !== $commonBudget)) {
                throw new \InvalidArgumentException('Every monotype confrontation must use the same exact budget on both sides.');
            }
            $commonBudget ??= $attackerBudget;
            $pair = $attackerUnit."\0".$defenderUnit;
            if (isset($pairs[$pair])) {
                throw new \InvalidArgumentException('The monotype matrix contains a duplicate ordered pair.');
            }
            $pairs[$pair] = true;
            $budgets[] = [
                'scenarioId' => $scenario['id'],
                'attacker' => ['unit' => $attackerUnit, 'count' => $attackerCount, 'budget' => $attackerBudget],
                'defender' => ['unit' => $defenderUnit, 'count' => $defenderCount, 'budget' => $defenderBudget],
            ];
        }
        if (count($pairs) !== count($valuation) ** 2) {
            throw new \InvalidArgumentException('The monotype matrix is incomplete.');
        }

        return [$commonBudget ?? 0, $budgets];
    }

    /** @param mixed $army @param array<string, int> $valuation @return array{string, int, int} */
    private function monotype(mixed $army, array $valuation): array
    {
        if (!is_array($army) || array_is_list($army)) {
            throw new \InvalidArgumentException('A monotype army must be an object.');
        }
        foreach ($army as $count) {
            if (!is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('Monotype counts must be non-negative integers.');
            }
        }
        $positive = array_filter($army, static fn (int $count): bool => $count > 0);
        if (1 !== count($positive)) {
            throw new \InvalidArgumentException('Every army must contain exactly one positive monotype count.');
        }
        $unit = array_key_first($positive);
        if (!is_string($unit) || !isset($valuation[$unit])) {
            throw new \InvalidArgumentException('The monotype unit is unknown.');
        }
        $count = $positive[$unit];

        return [$unit, $count, $count * $valuation[$unit]];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function zone(array $row, string $metric, string $referenceId): array
    {
        $center = ['x' => $row['vector']['x']['to'], 'y' => $row['vector']['y'][$metric]['to']];
        if ((!is_float($center['x']) && !is_int($center['x'])) || (!is_float($center['y']) && !is_int($center['y']))) {
            throw new \InvalidArgumentException('A monotype candidate coordinate is unavailable.');
        }

        return [
            'id' => implode('-', [$row['scenarioId'], $row['side'], 'tip', $metric]),
            'scenarioId' => $row['scenarioId'],
            'side' => $row['side'],
            'endpoint' => 'tip',
            'xMetric' => 'winRate',
            'yMetric' => $metric,
            'shape' => 'ellipse',
            'center' => $center,
            'radii' => ['x' => self::RADIUS_X, 'y' => self::RADIUS_Y],
            'enabled' => true,
            'approval' => 'draft',
            'source' => [
                'kind' => 'observation',
                'referenceId' => $referenceId,
                'referencePointId' => $row['scenarioId'].'/'.$row['side'].'/tip',
                'originalCenter' => $center,
                'modifiedManually' => false,
            ],
        ];
    }
}
