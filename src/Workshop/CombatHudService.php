<?php

namespace Waar\MicroCombat\Workshop;

final class CombatHudService
{
    public const MONOTYPE_BUDGET = 30000;
    public const WAVE_SIZE = 50;
    public const TOTAL_ITERATIONS = 10000;

    private CohortRuntime $runtime;

    public function __construct(
        ?CohortRuntime $runtime = null,
        private readonly CohortRequestFactory $requests = new CohortRequestFactory(),
    ) {
        $this->runtime = $runtime ?? new ProcessCohortRuntime(campaignRanges: true);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function wave(array $request): array
    {
        if ($unknown = array_diff(array_keys($request), ['requestId', 'profile', 'armies', 'weather', 'modifiers', 'seed', 'startIteration'])) {
            throw new \InvalidArgumentException('Champ HUD inconnu : '.reset($unknown).'.');
        }
        if (!is_string($request['requestId'] ?? null) || trim($request['requestId']) === '' || strlen($request['requestId']) > 80) {
            throw new \InvalidArgumentException('Identifiant de requête attendu (80 caractères maximum).');
        }
        $start = $request['startIteration'] ?? null;
        if (!is_int($start) || $start < 0 || $start >= self::TOTAL_ITERATIONS || $start % self::WAVE_SIZE !== 0) {
            throw new \InvalidArgumentException('Début de vague attendu entre 0 et 9 950, par pas de 50.');
        }
        $seed = $request['seed'] ?? null;
        if (!is_int($seed) || $seed < 0 || $seed > 2147483647) {
            throw new \InvalidArgumentException('Seed entière attendue entre 0 et 2³¹−1.');
        }
        $weather = $request['weather'] ?? null;
        if (!is_string($weather) || !in_array($weather, EngineProfile::WEATHER, true)) {
            throw new \InvalidArgumentException('Météo commune inconnue.');
        }

        $armyInput = $request['armies'] ?? null;
        if (!is_array($armyInput) || array_is_list($armyInput) || array_diff(array_keys($armyInput), ['A', 'B'])) {
            throw new \InvalidArgumentException('Les armées doivent être séparées entre les camps A et B.');
        }
        $armies = [];
        foreach (['A', 'B'] as $camp) {
            $armies[$camp] = $this->army($armyInput[$camp] ?? null, $camp);
        }
        $modifiers = $request['modifiers'] ?? ['A' => [], 'B' => []];
        if (!is_array($modifiers) || array_is_list($modifiers)) {
            throw new \InvalidArgumentException('Les effets doivent être séparés par camp.');
        }
        if ($unknown = array_diff(array_keys($modifiers), ['A', 'B'])) {
            throw new \InvalidArgumentException('Camp d’effets inconnu : '.reset($unknown).'.');
        }
        foreach (['A', 'B'] as $camp) {
            if (!is_array($modifiers[$camp] ?? null) || !array_is_list($modifiers[$camp])) {
                throw new \InvalidArgumentException('Liste d’effets invalide pour le camp '.$camp.'.');
            }
        }

        $profile = EngineProfile::fromArray(is_array($request['profile'] ?? null) ? $request['profile'] : []);
        $costs = $profile->costs();
        $scenarios = [];
        $metadata = [];
        foreach (array_keys(EngineProfile::UNIT_COSTS) as $attacking) {
            $attackerCount = intdiv(self::MONOTYPE_BUDGET, $costs[$attacking]);
            if ($attackerCount < 1) {
                throw new \InvalidArgumentException('Le coût du type '.$attacking.' dépasse le budget monotype de 30 000 Or.');
            }
            foreach (array_keys(EngineProfile::UNIT_COSTS) as $defending) {
                $defenderCount = intdiv(self::MONOTYPE_BUDGET, $costs[$defending]);
                if ($defenderCount < 1) {
                    throw new \InvalidArgumentException('Le coût du type '.$defending.' dépasse le budget monotype de 30 000 Or.');
                }
                $id = 'mono:'.$attacking.'>'.$defending.':A>B';
                $this->addScenario($scenarios, $metadata, $id, 'monotype', $attacking, $defending, 'A', 'B',
                    [$attacking => $attackerCount], [$defending => $defenderCount], [], [], $profile, $weather, $seed, $costs);
                if ($attacking === $defending) {
                    $id = 'mono:'.$attacking.'>'.$defending.':B>A';
                    $this->addScenario($scenarios, $metadata, $id, 'monotype', $attacking, $defending, 'B', 'A',
                        [$attacking => $attackerCount], [$defending => $defenderCount], [], [], $profile, $weather, $seed, $costs);
                }
            }
        }
        $this->addScenario($scenarios, $metadata, 'free:A>B', 'free', 'A', 'B', 'A', 'B',
            $armies['A'], $armies['B'], $modifiers['A'], $modifiers['B'], $profile, $weather, $seed, $costs);
        $this->addScenario($scenarios, $metadata, 'free:B>A', 'free', 'B', 'A', 'B', 'A',
            $armies['B'], $armies['A'], $modifiers['B'], $modifiers['A'], $profile, $weather, $seed, $costs);

        $consequences = [
            'policyVersion' => CohortRequestFactory::POLICY_VERSION,
            'compressionPercent' => $profile->lossCompressionPercent,
            'capturePercent' => $profile->capturePercent,
        ];
        $engineRequest = [
            'schemaVersion' => 'waar-combat-campaign-batch-request/1',
            'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION,
            'ruleset' => $profile->ruleset(),
            'baseSeed' => $seed,
            'iterations' => self::WAVE_SIZE,
            'startIteration' => $start,
            'totalIterations' => self::TOTAL_ITERATIONS,
            'consequences' => $consequences,
            'scenarios' => $scenarios,
        ];
        $batch = $this->runtime->batch($engineRequest);
        CohortRequestFactory::assertProvenance($batch['consequenceProvenance'] ?? [], $consequences);
        CohortRequestFactory::assertBatchRandomProvenance($batch, $scenarios);
        if (($batch['unitOrder'] ?? null) !== array_keys(EngineProfile::UNIT_COSTS)
            || ($batch['projectedCategoryOrder'] ?? null) !== ['healthy', 'wounded', 'dead', 'prisoners']) {
            throw new \RuntimeException('Ordre du résultat batch incompatible.');
        }
        if (($batch['totalCombats'] ?? null) !== self::WAVE_SIZE * count($scenarios)
            || count($batch['scenarios'] ?? []) !== count($scenarios)) {
            throw new \RuntimeException('Vague HUD incomplète renvoyée par le moteur.');
        }

        $rows = [];
        foreach ($batch['scenarios'] ?? [] as $scenario) {
            $meta = $metadata[$scenario['id']] ?? throw new \RuntimeException('Scénario HUD inattendu.');
            $key = $meta['kind'].':'.$meta['attacker'].'>'.$meta['defender'];
            $rows[$key] ??= [
                'id' => $key,
                'kind' => $meta['kind'],
                'attacker' => $meta['attacker'],
                'defender' => $meta['defender'],
                'attackerBudget' => $meta['attackerBudget'],
                'defenderBudget' => $meta['defenderBudget'],
                'samples' => 0,
                'attackerWins' => 0,
                'draws' => 0,
                'defenderWins' => 0,
                'roundSum' => 0,
                'attackerProjected' => ['dead' => 0, 'wounded' => 0, 'prisoners' => 0],
                'defenderProjected' => ['dead' => 0, 'wounded' => 0, 'prisoners' => 0],
            ];
            $result = $scenario['result'];
            if (($result['samples'] ?? null) !== self::WAVE_SIZE) {
                throw new \RuntimeException('Nombre d’échantillons HUD incompatible.');
            }
            $rows[$key]['samples'] += $result['samples'];
            $rows[$key]['attackerWins'] += $result['attackerWins'];
            $rows[$key]['draws'] += $result['draws'];
            $rows[$key]['defenderWins'] += $result['defenderWins'];
            $rows[$key]['roundSum'] += $result['roundSum'];
            $this->addProjected($rows[$key]['attackerProjected'], $result['attackerProjectedByType']);
            $this->addProjected($rows[$key]['defenderProjected'], $result['defenderProjectedByType']);
        }

        return [
            'requestId' => (string)($request['requestId'] ?? ''),
            'profileFingerprint' => $profile->semanticFingerprint(),
            'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION,
            'monotypeBudget' => self::MONOTYPE_BUDGET,
            'iterationRange' => ['start' => $start, 'endExclusive' => $start + self::WAVE_SIZE, 'total' => self::TOTAL_ITERATIONS],
            'totalCombats' => $batch['totalCombats'],
            'rows' => array_values($rows),
        ];
    }

    /** @return array<string,int> */
    private function army(mixed $value, string $camp): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Armée '.$camp.' invalide.');
        }
        if ($unknown = array_diff(array_keys($value), array_keys(EngineProfile::UNIT_COSTS))) {
            throw new \InvalidArgumentException('Type inconnu dans l’armée '.$camp.' : '.reset($unknown).'.');
        }
        $army = [];
        $total = 0;
        foreach (EngineProfile::UNIT_COSTS as $type => $unused) {
            $count = $value[$type] ?? 0;
            if (!is_int($count) || $count < 0 || $count > 1000000) {
                throw new \InvalidArgumentException('Effectif invalide pour '.$camp.'.'.$type.'.');
            }
            $army[$type] = $count;
            $total += $count;
        }
        if ($total < 1) {
            throw new \InvalidArgumentException('Chaque camp doit contenir au moins une unité.');
        }
        return $army;
    }

    /** @param list<array<string,mixed>> $scenarios @param array<string,array<string,mixed>> $metadata */
    private function addScenario(array &$scenarios, array &$metadata, string $id, string $kind, string $attacker,
        string $defender, string $attackerIdentity, string $defenderIdentity, array $attackerUnits,
        array $defenderUnits, array $attackerModifiers, array $defenderModifiers, EngineProfile $profile,
        string $weather, int $seed, array $costs): void
    {
        $combat = $this->requests->combat($profile, $attackerUnits, $defenderUnits, $seed, $weather, $weather,
            $attackerModifiers, $defenderModifiers, 'none', $attackerIdentity, $defenderIdentity);
        $scenarios[] = [
            'id' => $id,
            'armyIdentities' => $combat['armyIdentities'],
            'attacker' => $combat['attacker'],
            'defender' => $combat['defender'],
        ];
        $cost = static fn (array $army): int => array_sum(array_map(
            static fn (string $type, int $count): int => $costs[$type] * $count,
            array_keys($army),
            $army,
        ));
        $metadata[$id] = [
            'kind' => $kind,
            'attacker' => $attacker,
            'defender' => $defender,
            'attackerBudget' => $cost($attackerUnits),
            'defenderBudget' => $cost($defenderUnits),
        ];
    }

    /** @param array{dead:int,wounded:int,prisoners:int} $target @param list<list<int>> $byType */
    private function addProjected(array &$target, array $byType): void
    {
        foreach ($byType as $counts) {
            $target['wounded'] += $counts[1];
            $target['dead'] += $counts[2];
            $target['prisoners'] += $counts[3];
        }
    }
}
