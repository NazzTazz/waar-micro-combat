<?php

namespace Waar\MicroCombat\Bagaar;

use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

/** Deterministic, bounded hourly era. State is data and can be persisted between steps. */
final class EraSimulator
{
    public const COMBAT_ARCHIVE_FORMAT = 'gzip-base64-json/1';
    public const DECISION_VERSION = 'bagaar-builtin-policies/11';
    public const LUA_DECISION_VERSION = 'bagaar-lua-policy/7';
    public const MULTI_LUA_DECISION_VERSION = 'bagaar-lua-policy/6';
    private const PREVIOUS_BUILTIN_DECISION_VERSION = 'bagaar-builtin-policies/10';
    private const PREVIOUS_MULTI_LUA_DECISION_VERSION = 'bagaar-lua-policy/5';
    private const PREVIOUS_SINGLE_LUA_DECISION_VERSION = 'bagaar-lua-policy/4';
    private const LEGACY_DECISION_VERSION = 'bagaar-builtin-policies/8';
    private const LEGACY_LUA_DECISION_VERSION = 'bagaar-lua-policy/1';
    private const PREVIOUS_DECISION_VERSION = 'bagaar-builtin-policies/9';
    private const PREVIOUS_LUA_DECISION_VERSION = 'bagaar-lua-policy/3';
    private const OLDER_LUA_DECISION_VERSION = 'bagaar-lua-policy/2';
    private CohortRuntime $runtime;
    private CohortRequestFactory $requests;

    public function __construct(private readonly EngineProfile $profile, ?CohortRuntime $runtime = null,
        private readonly ?LuaPolicy $luaPolicy = null, private readonly array $luaScripts = [],
        private readonly array $spares = [])
    {
        $this->runtime = $runtime ?? new ProcessCohortRuntime();
        $this->requests = new CohortRequestFactory();
    }

    /** @param list<array{id:string,policy:string,name?:string,activity?:string,aggressionPercent?:int,soldierParadigm?:bool,hacker?:bool,protester?:bool,scriptKey?:string}> $accounts */
    public function start(int $seed, int $totalTicks, array $accounts): array
    {
        if ($seed < 0 || $seed > 2147483647 || $totalTicks < 1 || $totalTicks > 1440 || count($accounts) < 2 || count($accounts) > 32
            || count($this->spares) > 64) {
            throw new \InvalidArgumentException('Seed, durée ou nombre de comptes invalide.');
        }
        $players = [];
        foreach ($accounts as $entry) {
            $id = $entry['id'] ?? null;
            $policy = $entry['policy'] ?? null;
            $name = $entry['name'] ?? $id;
            $activity = $entry['activity'] ?? ($policy === 'casual' ? 'casual-morning' : 'all-day');
            $aggression = $entry['aggressionPercent'] ?? 100;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || isset($players[$id])
                || !in_array($policy, [...BuiltinPolicy::NAMES, 'lua'], true)
                || ($policy === 'lua' && $this->luaPolicy === null)) {
                throw new \InvalidArgumentException('Identifiant ou profil joueur invalide.');
            }
            if (!is_string($name) || trim($name) === '' || strlen($name) > 64
                || !is_string($activity) || !in_array($activity, PlayerSchedule::WINDOWS, true)
                || !is_int($aggression) || $aggression < 1 || $aggression > 200
                || (isset($entry['scriptKey']) && (!is_string($entry['scriptKey'])
                    || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $entry['scriptKey'])))) {
                throw new \InvalidArgumentException('Identité ou rythme joueur invalide.');
            }
            $players[$id] = AccountRules::initial($id, $policy);
            $players[$id]['name'] = $name;
            $players[$id]['originName'] = $name;
            $players[$id]['resetCount'] = 0;
            $players[$id]['joinedTick'] = 0;
            $players[$id]['activity'] = $activity;
            $players[$id]['aggressionPercent'] = $aggression;
            $profileType = $policy === 'lua' ? ($entry['scriptKey'] ?? 'lua') : $policy;
            if ($policy === 'lua') {
                $intent = $this->luaPolicy->intention($id);
                $players[$id]['luaMemory'] = [];
                $players[$id]['luaGoal'] = $intent['goal'];
                $players[$id]['luaMethod'] = $intent['method'];
                if (isset($entry['scriptKey'])) {
                    $players[$id]['scriptKey'] = $entry['scriptKey'];
                }
                $players[$id]['parameters'] = LuaParameters::values($this->luaPolicy->parameters($id), $entry['parameters'] ?? []);
            }
            if ($profileType === 'scripteur' && ($entry['hacker'] ?? false) === true) {
                $players[$id]['hacker'] = true;
                $players[$id]['hackerVictims'] = [];
            }
            if ($profileType === 'casual' && ($entry['protester'] ?? false) === true) {
                $players[$id]['protester'] = true;
            }
            if ($profileType === 'grenouille') {
                $players[$id]['soldierParadigm'] = (bool)($entry['soldierParadigm'] ?? false);
            }
        }
        if ($this->luaPolicy !== null) {
            $luaIds = array_keys(array_filter($players, static fn (array $player): bool => $player['policy'] === 'lua'));
            $scriptIds = $this->luaPolicy->accountIds();
            sort($luaIds);
            if ($scriptIds !== null) {
                sort($scriptIds);
            }
            if ($scriptIds === null ? count($luaIds) !== 1 : $luaIds !== $scriptIds) {
                throw new \InvalidArgumentException('Les scripts Lua doivent correspondre aux comptes contrôlés.');
            }
        }
        ksort($players);
        foreach ($players as $id => &$player) {
            if (($player['scriptKey'] ?? $player['policy']) === 'fermier') {
                $player['fridges'] = array_slice(array_values(array_diff(array_keys($players), [$id])), 0, 2);
            }
        }
        unset($player);
        $arrivalPool = [];
        foreach ($accounts as $entry) {
            $arrivalPool[] = array_intersect_key($entry, array_flip(['policy', 'scriptKey', 'parameters', 'activity',
                'aggressionPercent', 'soldierParadigm'])) + ['weight' => 1];
        }
        foreach ($this->spares as $entry) $arrivalPool[] = $entry;
        return ['schemaVersion' => 'waar-bagaar-era/1', 'manifest' => [
            'seed' => $seed, 'profileFingerprint' => $this->profile->semanticFingerprint(),
            'runtime' => $this->runtime->provenance(), 'attackRange' => HostRules::ATTACK_RANGE,
            'spyRange' => 30, 'weatherConvention' => 'one-weather-both-sides/1',
            'simulatedStartAt' => '2026-01-01T00:00:00Z',
            'decisionVersion' => $this->decisionVersion(), 'hostRuleVersion' => 'bagaar-host-rules/3',
            'initialPopulation' => $accounts, 'arrivalPool' => $arrivalPool,
        ], 'tick' => 0, 'totalTicks' => $totalTicks, 'players' => $players,
            'arrivalPool' => $arrivalPool,
            'spawnSerial' => 0, 'spontaneousArrivals' => 0,
            'villages' => [], 'villageAttacks' => [], 'candidate' => null, 'candidateHours' => 0,
            'rwaa' => null, 'rwaaPv' => 0, 'events' => [], 'combats' => [], 'combatCount' => 0,
            'inspectionMetrics' => [], 'goldFlowScale' => [], 'frames' => []];
    }

    public function advance(array $state, int $steps = 1): array
    {
        if (($state['schemaVersion'] ?? null) !== 'waar-bagaar-era/1'
            || ($state['manifest']['profileFingerprint'] ?? null) !== $this->profile->semanticFingerprint()
            || !in_array($state['manifest']['decisionVersion'] ?? null, $this->acceptedDecisionVersions(), true)
            || $steps < 1 || $steps > 24) {
            throw new \InvalidArgumentException('État, preset ou nombre de ticks invalide.');
        }
        for ($step = 0; $step < $steps && $state['tick'] < $state['totalTicks']; $step++) {
            $state = $this->tick($state);
        }
        return $state;
    }

    private function tick(array $state): array
    {
        $state['tick']++;
        $tick = $state['tick'];
        $master = $state['manifest']['seed'];
        $weather = EngineProfile::WEATHER[self::random($master, $tick, 'weather') % count(EngineProfile::WEATHER)];
        $state['goldFlow'] = [];
        foreach ($state['players'] as $id => $player) {
            $state['players'][$id] = AccountRules::hourly($player, 40 + self::random($master, $tick, 'hospital:'.$id) % 41);
            $state['goldFlow'][$id]['income'] = $state['players'][$id]['gold'] - $player['gold'];
            [$state['players'][$id], $change] = PlayerEngagement::resume($state['players'][$id], $tick);
            if ($change !== null) {
                $state['events'][] = ['tick' => $tick, 'type' => $change, 'actor' => $id];
            }
        }
        foreach ($state['villages'] as $id => $village) {
            $caps = VillageRules::caps($village['glory'], $state['players'], $this->profile->costs());
            $state['villages'][$id] = VillageRules::refill($village, $caps);
            $state['goldFlow'][$id]['income'] = $state['villages'][$id]['gold'] - $village['gold'];
            $state['villages'][$id]['defenses'] = 9999;
        }
        $state['villageAttacks'] = [];
        $state['spyAttempts'] = [];
        $state['spyResults'] = [];
        $state['decisionCalls'] = [];
        $this->checkRwaa($state);
        $this->ensureVillages($state);
        foreach (array_keys($state['players']) as $id) {
            if (($state['players'][$id]['status'] ?? 'active') !== 'active'
                || !PlayerSchedule::isActive($state['players'][$id]['activity'], $tick)) {
                continue;
            }
            if ($this->monkeyRules($state)) $state['players'][$id]['lastSeenTick'] = $tick;
            $attempts = [];
            $policy = $this->policyFor($state['players'][$id]['policy']);
            $newRules = $this->monkeyRules($state);
            for ($actionIndex = 0; $actionIndex < ($newRules ? 128 : 16); $actionIndex++) {
                if (($state['players'][$id]['status'] ?? 'active') !== 'active'
                    || ($state['players'][$id]['joinedTick'] ?? 0) >= $tick) {
                    break;
                }
                if ($newRules && !$this->consumeDecision($state, $id)) break;
                $view = PlayerObservation::fromState($state, $id, $this->profile->costs(), $attempts);
                $action = $policy->next($view);
                if ($policy instanceof LuaPolicy && (!$policy->compact() || ($action ?? null) === null)) {
                    $this->saveLuaState($state, $id, $policy);
                }
                if ($action === null) {
                    break;
                }
                if ($policy instanceof LuaPolicy && $policy->compact() && ($action['type'] ?? null) === 'abandon') {
                    $this->saveLuaState($state, $id, $policy);
                }
                try {
                    $results = $this->act($state, $id, $action, $weather, $actionIndex);
                    $attempts[] = $results === null ? $action : [...$action, 'results' => $results];
                } catch (\DomainException $error) {
                    $attempts[] = $action;
                    $state['events'][] = ['tick' => $tick, 'type' => 'rejected', 'actor' => $id,
                        'action' => $action['type'], 'reason' => $error->getMessage()];
                }
            }
        }
        if ($tick % 24 === 0
            && ($state['spontaneousArrivals'] ?? 0) < max(1, intdiv($state['totalTicks'], 240))
            && self::random($master, $tick, 'spontaneous-arrival') % 10 === 0) {
            $this->spawnEntrant($state, null);
            $state['spontaneousArrivals'] = ($state['spontaneousArrivals'] ?? 0) + 1;
        }
        if ($this->luaPolicy?->compact()) {
            foreach ($state['players'] as $id => $player) {
                if ($player['policy'] === 'lua' && ($player['status'] ?? 'active') !== 'abandoned') {
                    $this->saveLuaState($state, $id, $this->luaPolicy);
                }
            }
        }
        foreach ($state['goldFlow'] as $id => $flow) {
            $state['goldFlowScale'][$id] = max($state['goldFlowScale'][$id] ?? 1,
                $flow['income'] ?? 0, ($flow['invested'] ?? 0) + ($flow['pillaged'] ?? 0));
        }
        $points = [];
        $playerNames = array_map(static fn (array $player): string => $player['name'], $state['players']);
        foreach ($state['players'] as $id => $player) {
            $value = HostRules::armyValue($player['army'], $this->profile->costs());
            $state['players'][$id]['peakArmyGold'] = max($player['peakArmyGold'], $value);
            $intent = $player['policy'] === 'lua' ? ['goal' => $player['luaGoal'] ?? $this->luaPolicy->intention($id)['goal'],
                'method' => $player['luaMethod'] ?? $this->luaPolicy->intention($id)['method']]
                : (new BuiltinPolicy($player['policy']))->intention($player, $tick, $state['totalTicks'], $playerNames, $state['rwaa']);
            $point = ['id' => $id, 'name' => $player['name'], 'kind' => 'player', 'policy' => $player['policy'],
                'armyGold' => $value, 'army' => $player['army'], 'glory' => $player['glory'], 'gold' => $player['gold'],
                'mineLevel' => $player['mineLevel'], 'mineProduction' => HostRules::mineProduction($player['mineLevel']),
                'hospitalLevel' => $player['hospitalLevel'], 'hospitalOccupied' => array_sum($player['hospital']),
                'record' => $player['record'], 'activity' => $player['activity'], 'aggressionPercent' => $player['aggressionPercent'],
                'powerDestroyed' => $state['inspectionMetrics'][$id]['powerDestroyed'] ?? 0,
                'powerLost' => $state['inspectionMetrics'][$id]['powerLost'] ?? 0,
                'goldLooted' => $state['inspectionMetrics'][$id]['goldLooted'] ?? 0,
                'goldFlow' => $state['goldFlow'][$id] ?? [], 'goldFlowScale' => $state['goldFlowScale'][$id] ?? 1,
                'resetCount' => $player['resetCount'] ?? 0,
                'status' => $player['status'] ?? 'active', 'pauseUntil' => $player['pauseUntil'] ?? null,
                'goal' => $intent['goal'], 'method' => $intent['method']];
            if ($this->combatReports($state)) {
                $point['prisoners'] = $player['prisoners'];
                $point['prisonerProduction'] = AccountRules::prisonerProduction($player);
            }
            if (isset($player['scriptKey'])) {
                $point['scriptKey'] = $player['scriptKey'];
            }
            $points[] = $point;
        }
        $villages = [];
        foreach ($state['villages'] as $id => $village) {
            $villages[] = ['id' => $id, 'kind' => 'village', 'policy' => 'village',
                'armyGold' => HostRules::armyValue($village['army'], $this->profile->costs()),
                'glory' => $village['glory'], 'gold' => $village['gold'], 'army' => $village['army'],
                'record' => $state['inspectionMetrics'][$id]['record'] ?? ['wins' => 0, 'draws' => 0, 'losses' => 0],
                'goldDistributed' => $state['inspectionMetrics'][$id]['goldDistributed'] ?? 0,
                'goldFlow' => $state['goldFlow'][$id] ?? [], 'goldFlowScale' => $state['goldFlowScale'][$id] ?? 1,
                'goldMax' => $village['goldMax'] ?? $village['gold'], 'goldRefill' => $village['goldRefill'] ?? 0];
        }
        $state['frames'][] = ['tick' => $tick, 'weather' => $weather, 'points' => $points,
            'villages' => $villages,
            'candidate' => $state['candidate'], 'candidateHours' => $state['candidateHours'],
            'rwaa' => $state['rwaa'], 'rwaaPv' => $state['rwaaPv'],
            'eventCount' => ($state['eventCount'] ?? 0) + count($state['events'])];
        return $state;
    }

    private function act(array &$state, string $id, array $action, string $weather, int $index): ?array
    {
        $type = $action['type'] ?? null;
        $player = $state['players'][$id];
        switch ($type) {
            case 'mine':
                $state['players'][$id] = AccountRules::buyMine($player);
                break;
            case 'hospital':
                $state['players'][$id] = AccountRules::buyHospital($player);
                break;
            case 'recruit':
                $state['players'][$id] = AccountRules::recruit($player, $action['units'], $this->profile->costs());
                break;
            case 'heal':
                $state['players'][$id] = AccountRules::heal($player, $this->profile->costs());
                break;
            case 'autoSurrender':
                $state['players'][$id]['autoSurrender'] = (bool)$action['enabled'];
                break;
            case 'surrender':
                $state['players'][$id] = HostRules::manualSurrender($player);
                if ($state['rwaa'] === $id) {
                    $state['rwaa'] = null;
                    $state['rwaaPv'] = 0;
                    $state['events'][] = ['tick' => $state['tick'], 'type' => 'rwaa-ended', 'actor' => $id];
                }
                break;
            case 'reset':
                if ($player['policy'] !== 'lua' || !HostRules::canReset($player, $state['tick'])) {
                    throw new \DomainException('Reset indisponible avant plus de 24 ticks depuis le précédent.');
                }
                $this->resetAccount($state, $id);
                break;
            case 'abandon':
                if ($player['policy'] !== 'lua') {
                    throw new \DomainException('Abandon réservé au joueur Lua.');
                }
                $state['players'][$id]['status'] = 'abandoned';
                $state['players'][$id]['pauseUntil'] = null;
                break;
            case 'phase':
                if ($player['policy'] !== 'ascenseur' || !in_array($action['value'] ?? null, ['raid', 'surrender'], true)) {
                    throw new \DomainException('Phase de profil invalide.');
                }
                $state['players'][$id]['cyclePhase'] = $action['value'];
                break;
            case 'spy':
                if ($this->monkeyRules($state)) {
                    $targets = $action['targets'] ?? (isset($action['target']) ? [$action['target']] : null);
                    if (!is_array($targets) || !array_is_list($targets) || $targets === [] || count($targets) > 64) {
                        throw new \DomainException('Groupe d’espionnage invalide.');
                    }
                    $results = [];
                    foreach ($targets as $targetId) {
                        if (!is_string($targetId) || $targetId === '') throw new \DomainException('Cible d’espionnage invalide.');
                    }
                    foreach (array_unique($targets) as $targetId) {
                        $used = $state['spyAttempts'][$id] ?? 0;
                        $state['spyAttempts'][$id] = $used + 1;
                        try {
                            if ($used >= 64) throw new \DomainException('Limite de 64 espionnages atteinte.');
                            $target = $state['players'][$targetId] ?? $state['villages'][$targetId] ?? null;
                            if ($target === null) throw new \DomainException('Cible d’espionnage inconnue.');
                            $state['players'][$id] = AccountRules::spy($state['players'][$id], $target, $state['manifest']['spyRange']);
                            $state['players'][$id]['spies'][$targetId]['tick'] = $state['tick'];
                            $state['events'][] = ['tick' => $state['tick'], 'type' => 'spy', 'actor' => $id, 'target' => $targetId];
                            $results[] = ['target' => $targetId, 'ok' => true];
                        } catch (\DomainException $error) {
                            $results[] = ['target' => $targetId, 'ok' => false, 'reason' => $error->getMessage()];
                        }
                    }
                    $state['spyResults'][$id][] = $results;
                    $state['goldFlow'][$id]['invested'] = ($state['goldFlow'][$id]['invested'] ?? 0)
                        + $player['gold'] - $state['players'][$id]['gold'];
                    return $results;
                }
                $target = $state['players'][$action['target']] ?? $state['villages'][$action['target']] ?? null;
                if ($target === null) {
                    throw new \DomainException('Cible d’espionnage inconnue.');
                }
                $state['players'][$id] = AccountRules::spy($player, $target, $state['manifest']['spyRange']);
                $state['players'][$id]['spies'][$target['id']]['tick'] = $state['tick'];
                break;
            case 'attack':
                $this->attack($state, $id, (string)$action['target'], $weather, $index);
                return null;
            default:
                throw new \DomainException('Action inconnue.');
        }
        if ($type === 'reset') {
            $state['goldFlow'][$id]['reset'] = true;
        } else {
            $spent = $player['gold'] - $state['players'][$id]['gold'];
            if ($spent > 0) {
                $state['goldFlow'][$id]['invested'] = ($state['goldFlow'][$id]['invested'] ?? 0) + $spent;
            }
        }
        $event = ['tick' => $state['tick'], 'type' => $type, 'actor' => $id];
        if ($type === 'spy') {
            $event['target'] = $action['target'];
        } elseif ($type === 'recruit') {
            $event['units'] = $action['units'];
        }
        $state['events'][] = $event;
        if ($type === 'abandon') {
            $this->spawnEntrant($state, $id);
        }
        return null;
    }

    private function attack(array &$state, string $id, string $targetId, string $weather, int $index): void
    {
        $village = isset($state['villages'][$targetId]);
        $defender = $village ? $state['villages'][$targetId] : ($state['players'][$targetId] ?? null);
        if ($defender === null || $id === $targetId || ($village && $state['rwaa'] === $id)) {
            throw new \DomainException('Cible inaccessible.');
        }
        if ($village && ($state['villageAttacks'][$id][$targetId] ?? 0) >= 3) {
            throw new \DomainException('Quota de village atteint.');
        }
        $attacker = $state['players'][$id];
        $attackerArmyGold = HostRules::armyValue($attacker['army'], $this->profile->costs());
        if (!HostRules::canAttack($attacker['glory'], $defender['glory'], $attacker['attacks'], $defender['defenses'])
            || array_sum($attacker['army']) === 0) {
            throw new \DomainException('Portée, quota ou armée insuffisante.');
        }
        $ordinal = $state['combatCount'] ?? count($state['combats']);
        $combatSeed = self::random($state['manifest']['seed'], $state['tick'], 'combat:'.$ordinal.':'.$index);
        $request = $this->requests->combat($this->profile, $attacker['army'], $defender['army'], $combatSeed,
            $weather, $weather, [], [], 'none', 'A', 'B');
        $report = $this->runtime->resolve($request);
        CohortRequestFactory::assertProvenance($report['consequences'] ?? [], $request['consequences']);
        CohortRequestFactory::assertRandomProvenance($report['result']['snapshot'] ?? [], $request['armyIdentities']);
        $production = HostRules::mineProduction($defender['mineLevel']);
        $protected = (int) floor($production * (1 + 0.1 * $defender['protectionLevel']));
        $unprotected = max(0, $defender['gold'] - $protected);
        $lower = (int) ($unprotected * 0.1);
        $upper = (int) ($unprotected * 0.15);
        $loot = ($report['result']['winner'] ?? null) === 'attacker'
            ? $lower + self::random($state['manifest']['seed'], $state['tick'], 'loot:'.$ordinal) % ($upper - $lower + 1) : 0;
        $result = CombatTransition::apply($attacker, $defender, $report, $loot, $village);
        if ($result['event']['loot'] > 0) {
            $state['goldFlow'][$id]['income'] = ($state['goldFlow'][$id]['income'] ?? 0) + $result['event']['loot'];
            $state['goldFlow'][$targetId]['pillaged'] = ($state['goldFlow'][$targetId]['pillaged'] ?? 0) + $result['event']['loot'];
        }
        $lossValue = ['attacker' => 0, 'defender' => 0];
        foreach ($lossValue as $side => $_) {
            foreach ($this->profile->costs() as $type => $cost) {
                $losses = $result['event']['report'][$side]['types'][$type];
                $lossValue[$side] += ($losses['dead'] + $losses['wounded'] + $losses['prisoners']) * $cost;
            }
        }
        $state['inspectionMetrics'][$id]['powerDestroyed'] = ($state['inspectionMetrics'][$id]['powerDestroyed'] ?? 0) + $lossValue['defender'];
        $state['inspectionMetrics'][$id]['powerLost'] = ($state['inspectionMetrics'][$id]['powerLost'] ?? 0) + $lossValue['attacker'];
        $state['inspectionMetrics'][$id]['goldLooted'] = ($state['inspectionMetrics'][$id]['goldLooted'] ?? 0) + $result['event']['loot'];
        if ($village) {
            $key = $result['event']['winner'] === 'defender' ? 'wins' : ($result['event']['winner'] === null ? 'draws' : 'losses');
            $state['inspectionMetrics'][$targetId]['record'][$key] = ($state['inspectionMetrics'][$targetId]['record'][$key] ?? 0) + 1;
            $state['inspectionMetrics'][$targetId]['goldDistributed'] = ($state['inspectionMetrics'][$targetId]['goldDistributed'] ?? 0) + $result['event']['loot'];
        } else {
            $state['inspectionMetrics'][$targetId]['powerDestroyed'] = ($state['inspectionMetrics'][$targetId]['powerDestroyed'] ?? 0) + $lossValue['attacker'];
            $state['inspectionMetrics'][$targetId]['powerLost'] = ($state['inspectionMetrics'][$targetId]['powerLost'] ?? 0) + $lossValue['defender'];
        }
        if (!$this->combatReports($state)) {
            unset($result['event']['report']);
        }
        if ($village) {
            if ($result['event']['winner'] === 'defender') {
                $result['attacker']['villageCautious'] = true;
            }
            if ($result['attacker']['villageCautious'] ?? false) {
                $result['attacker']['lastVillageAttackTick'] = $state['tick'];
            }
            if ($result['event']['winner'] === 'attacker') {
                unset($result['attacker']['villageFailures'][$targetId]);
            } elseif ($result['event']['winner'] === 'defender') {
                $result['attacker']['villageFailures'][$targetId] = $attackerArmyGold;
            }
        }
        $state['players'][$id] = $result['attacker'];
        if (($state['players'][$id]['hacker'] ?? false) && $result['event']['winner'] === 'attacker' && !$village) {
            $state['players'][$id]['hackerVictims'][$targetId] =
                ($state['players'][$id]['hackerVictims'][$targetId] ?? 0) + 1;
        }
        $state['players'][$id]['record'][$result['event']['winner'] === 'attacker' ? 'wins' : ($result['event']['winner'] === null ? 'draws' : 'losses')]++;
        [$state['players'][$id], $attackerChange] = PlayerEngagement::afterCombat($state['players'][$id], $state['tick'],
            $result['event']['winner'] === 'defender', $this->resilientRageux($state), $this->luaControlsEngagement($state));
        if ($village) {
            $state['villages'][$targetId] = $result['defender'];
            $state['villageAttacks'][$id][$targetId] = ($state['villageAttacks'][$id][$targetId] ?? 0) + 1;
        } else {
            $state['players'][$targetId] = $result['defender'];
            $state['players'][$targetId]['record'][$result['event']['winner'] === 'defender' ? 'wins' : ($result['event']['winner'] === null ? 'draws' : 'losses')]++;
            [$state['players'][$targetId], $defenderChange] = PlayerEngagement::afterCombat($state['players'][$targetId], $state['tick'],
                $result['event']['winner'] === 'attacker', $this->resilientRageux($state), $this->luaControlsEngagement($state));
            if ($state['players'][$targetId]['policy'] === 'rageux') {
                $state['players'][$targetId]['rageTarget'] = $id;
                $state['players'][$targetId]['rageUntil'] = $state['tick'] + 2;
            }
            if ($result['event']['surrender'] && $state['players'][$targetId]['policy'] === 'ascenseur') {
                $state['players'][$targetId]['cyclePhase'] = 'rebuild';
            }
            if ($state['rwaa'] === $targetId && $result['event']['winner'] === 'attacker') {
                $state['rwaaPv']--;
                if ($state['rwaaPv'] <= 0 || $result['event']['surrender']) {
                    if (!$result['event']['surrender']) {
                        $state['players'][$targetId] = HostRules::surrender($state['players'][$targetId]);
                        $result['event']['surrender'] = true;
                        if ($state['players'][$targetId]['policy'] === 'ascenseur') {
                            $state['players'][$targetId]['cyclePhase'] = 'rebuild';
                        }
                    }
                    $state['events'][] = ['tick' => $state['tick'], 'type' => 'rwaa-ended', 'actor' => $targetId];
                    $state['rwaa'] = null;
                    $state['rwaaPv'] = 0;
                }
            }
        }
        $event = ['tick' => $state['tick'], 'type' => 'combat', 'attacker' => $id,
            'defender' => $targetId, ...$result['event'], 'replayHash' => $report['result']['replayHash'] ?? null];
        $state['events'][] = $event;
        if (($state['traceDetached'] ?? false) === true) {
            $observation = array_intersect_key($event, array_flip(['tick', 'attacker', 'defender', 'winner', 'surrender', 'report']));
            foreach ([$id, $targetId] as $participant) {
                if (isset($state['players'][$participant])) {
                    $state['observationEvents'][$participant][] = $observation;
                    $state['observationEvents'][$participant] = array_slice($state['observationEvents'][$participant], -20);
                }
            }
        }
        foreach ([$id => $attackerChange, $targetId => $defenderChange ?? null] as $participant => $change) {
            if ($change !== null) {
                $state['events'][] = ['tick' => $state['tick'], 'type' => $change, 'actor' => $participant];
                if ($change === 'abandon') {
                    $this->spawnEntrant($state, $participant);
                } elseif ($change === 'reset') {
                    $this->resetAccount($state, $participant);
                }
            }
        }
        $payload = json_encode(['request' => $request, 'report' => $report], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($payload, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Compression de l’archive de combat impossible.');
        }
        $state['combats'][] = ['format' => self::COMBAT_ARCHIVE_FORMAT,
            'payload' => base64_encode($compressed), 'event' => $event];
        $state['combatCount'] = $ordinal + 1;
        foreach ([$id, $targetId] as $participant) {
            if (!isset($state['players'][$participant])
                || ($state['players'][$participant]['status'] ?? 'active') !== 'active'
                || ($state['players'][$participant]['joinedTick'] ?? 0) >= $state['tick']
                || !PlayerSchedule::isActive($state['players'][$participant]['activity'], $state['tick'])) {
                continue;
            }
            $policy = $this->policyFor($state['players'][$participant]['policy']);
            if ($this->monkeyRules($state) && !$this->consumeDecision($state, $participant)) continue;
            $reaction = $policy->afterCombat(PlayerObservation::fromState($state, $participant, $this->profile->costs()));
            if ($policy instanceof LuaPolicy && !$policy->compact()) {
                $this->saveLuaState($state, $participant, $policy);
            }
            if ($reaction !== null) {
                if ($policy instanceof LuaPolicy && $policy->compact() && ($reaction['type'] ?? null) === 'abandon') {
                    $this->saveLuaState($state, $participant, $policy);
                }
                try {
                    $this->act($state, $participant, $reaction, $weather, $index);
                } catch (\DomainException $error) {
                    $state['events'][] = ['tick' => $state['tick'], 'type' => 'rejected', 'actor' => $participant,
                        'action' => $reaction['type'], 'reason' => $error->getMessage()];
                }
            }
        }
    }

    private function spawnEntrant(array &$state, ?string $formerId): void
    {
        if ($formerId !== null && ($state['players'][$formerId]['policy'] ?? null) === 'lua') {
            $this->luaPolicy?->unregister($formerId);
        }
        $serial = ($state['spawnSerial'] ?? 0) + 1;
        $template = $this->monkeyRules($state) ? $this->pickArrival($state, $serial) : null;
        $entrant = PlayerEntrants::create($serial, $state['tick'], $template);
        $scriptKey = $entrant['scriptKey'] ?? $entrant['policy'];
        if ($this->luaPolicy?->accountIds() !== null && isset($this->luaScripts[$scriptKey])
            && ($entrant['policy'] === 'lua' || $template === null)) {
            $intent = $this->luaPolicy->register($entrant['id'], $this->luaScripts[$scriptKey]);
            $entrant['policy'] = 'lua';
            $entrant['scriptKey'] = $scriptKey;
            $entrant['luaMemory'] = [];
            $entrant['luaGoal'] = $intent['goal'];
            $entrant['luaMethod'] = $intent['method'];
            $entrant['parameters'] = LuaParameters::values($this->luaPolicy->parameters($entrant['id']), $template['parameters'] ?? []);
        }
        if (($entrant['scriptKey'] ?? $entrant['policy']) === 'fermier') {
            $entrant['fridges'] = array_slice(array_values(array_diff(array_keys($state['players']), [$formerId])), 0, 2);
        }
        $state['spawnSerial'] = $serial;
        $state['players'][$entrant['id']] = $entrant;
        if ($formerId !== null && $state['candidate'] === $formerId) {
            $state['candidate'] = null;
            $state['candidateHours'] = 0;
        }
        if ($formerId !== null && $state['rwaa'] === $formerId) {
            $state['rwaa'] = null;
            $state['rwaaPv'] = 0;
            $state['events'][] = ['tick' => $state['tick'], 'type' => 'rwaa-ended', 'actor' => $formerId];
        }
        $state['events'][] = ['tick' => $state['tick'], 'type' => 'arrival', 'actor' => $entrant['id'],
            'source' => $formerId === null ? 'spontaneous' : 'replacement',
            'predecessor' => $formerId];
    }

    private function resetAccount(array &$state, string $id): void
    {
        $state['players'][$id] = AccountRules::reset($state['players'][$id], $state['tick']);
        $state['goldFlow'][$id]['reset'] = true;
        if ($state['candidate'] === $id) {
            $state['candidate'] = null;
            $state['candidateHours'] = 0;
        }
        if ($state['rwaa'] === $id) {
            $state['rwaa'] = null;
            $state['rwaaPv'] = 0;
            $state['events'][] = ['tick' => $state['tick'], 'type' => 'rwaa-ended', 'actor' => $id];
        }
    }

    private function ensureVillages(array &$state): void
    {
        $glories = array_column($state['players'], 'glory');
        $reference = $state['rwaa'] === null ? max($glories) : $state['players'][$state['rwaa']]['glory'];
        foreach ($state['players'] as $player) {
            foreach (VillageRules::availableTiers($player['glory'], $reference) as $tier) {
                $id = 'village-'.$tier;
                if (isset($state['villages'][$id])) {
                    continue;
                }
                $caps = VillageRules::caps($tier, $state['players'], $this->profile->costs());
                $village = AccountRules::initial($id, 'village');
                $village['glory'] = $tier;
                $village['army'] = $caps['army'];
                $village['gold'] = $caps['gold'];
                $village['goldMax'] = $caps['gold'];
                $village['goldRefill'] = (int) ceil($caps['gold'] * 0.10);
                $village['defenses'] = 9999;
                $state['villages'][$id] = $village;
                $state['events'][] = ['tick' => $state['tick'], 'type' => 'village', 'id' => $id];
            }
        }
    }

    private function checkRwaa(array &$state): void
    {
        if ($state['rwaa'] !== null) {
            return;
        }
        $ranking = array_filter($state['players'], static fn (array $player): bool => ($player['status'] ?? 'active') === 'active');
        if (count($ranking) < 2) {
            $state['candidate'] = null;
            $state['candidateHours'] = 0;
            return;
        }
        uasort($ranking, static fn (array $a, array $b): int => [$b['glory'], $a['id']] <=> [$a['glory'], $b['id']]);
        $ids = array_keys($ranking);
        $first = $ranking[$ids[0]];
        $second = $ranking[$ids[1]];
        $leader = $first['glory'] >= 50 && $first['glory'] > $second['glory'] ? $first['id'] : null;
        if ($leader === null) {
            $state['candidate'] = null;
            $state['candidateHours'] = 0;
            return;
        }
        if ($state['candidate'] !== $leader) {
            $state['candidate'] = $leader;
            $state['candidateHours'] = 0;
            $state['events'][] = ['tick' => $state['tick'], 'type' => 'candidate', 'actor' => $leader];
            return;
        }
        $state['candidateHours']++;
        if ($state['candidateHours'] >= 24) {
            $state['rwaa'] = $leader;
            $state['rwaaPv'] = 20;
            $state['candidate'] = null;
            $state['events'][] = ['tick' => $state['tick'], 'type' => 'rwaa', 'actor' => $leader];
        }
    }

    private static function random(int $masterSeed, int $tick, string $purpose): int
    {
        return hexdec(substr(hash('sha256', $masterSeed.':'.$tick.':'.$purpose), 0, 8)) & 0x7fffffff;
    }

    private function monkeyRules(array $state): bool
    {
        return in_array($state['manifest']['decisionVersion'] ?? null,
            [self::DECISION_VERSION, self::LUA_DECISION_VERSION, self::MULTI_LUA_DECISION_VERSION], true);
    }

    private function consumeDecision(array &$state, string $id): bool
    {
        $used = $state['decisionCalls'][$id] ?? 0;
        if ($used >= 256) return false;
        $state['decisionCalls'][$id] = $used + 1;
        return true;
    }

    private function pickArrival(array $state, int $serial): array
    {
        $pool = $state['arrivalPool'] ?? [];
        if ($pool === []) return ['policy' => BuiltinPolicy::NAMES[($serial - 1) % count(BuiltinPolicy::NAMES)]];
        if ($this->luaPolicy !== null && $this->luaPolicy->accountIds() !== null
            && count($this->luaPolicy->accountIds()) >= 64) {
            $pool = array_values(array_filter($pool, static fn (array $entry): bool => $entry['policy'] !== 'lua'));
            if ($pool === []) return ['policy' => BuiltinPolicy::NAMES[($serial - 1) % count(BuiltinPolicy::NAMES)]];
        }
        $total = array_sum(array_column($pool, 'weight'));
        $point = self::random($state['manifest']['seed'], $state['tick'], 'entrant:'.$serial) / 2147483648 * $total;
        foreach ($pool as $entry) {
            $point -= $entry['weight'];
            if ($point < 0) return $entry;
        }
        return $pool[count($pool) - 1];
    }

    private function decisionVersion(): string
    {
        return $this->luaPolicy === null ? self::DECISION_VERSION
            : ($this->luaPolicy->accountIds() === null ? self::LUA_DECISION_VERSION : self::MULTI_LUA_DECISION_VERSION);
    }

    private function acceptedDecisionVersions(): array
    {
        return $this->luaPolicy === null
            ? [self::DECISION_VERSION, self::PREVIOUS_BUILTIN_DECISION_VERSION, self::PREVIOUS_DECISION_VERSION, self::LEGACY_DECISION_VERSION]
            : [self::MULTI_LUA_DECISION_VERSION, self::PREVIOUS_MULTI_LUA_DECISION_VERSION, self::LUA_DECISION_VERSION, self::PREVIOUS_SINGLE_LUA_DECISION_VERSION, self::PREVIOUS_LUA_DECISION_VERSION,
                self::OLDER_LUA_DECISION_VERSION, self::LEGACY_LUA_DECISION_VERSION];
    }

    private function resilientRageux(array $state): bool
    {
        return in_array($state['manifest']['decisionVersion'],
            [self::DECISION_VERSION, self::PREVIOUS_BUILTIN_DECISION_VERSION, self::PREVIOUS_DECISION_VERSION, self::MULTI_LUA_DECISION_VERSION, self::PREVIOUS_MULTI_LUA_DECISION_VERSION, self::LUA_DECISION_VERSION, self::PREVIOUS_SINGLE_LUA_DECISION_VERSION,
                self::PREVIOUS_LUA_DECISION_VERSION, self::OLDER_LUA_DECISION_VERSION], true);
    }

    private function luaControlsEngagement(array $state): bool
    {
        return in_array($state['manifest']['decisionVersion'],
            [self::MULTI_LUA_DECISION_VERSION, self::PREVIOUS_MULTI_LUA_DECISION_VERSION, self::LUA_DECISION_VERSION, self::PREVIOUS_SINGLE_LUA_DECISION_VERSION, self::PREVIOUS_LUA_DECISION_VERSION], true);
    }

    private function combatReports(array $state): bool
    {
        return in_array($state['manifest']['decisionVersion'],
            [self::DECISION_VERSION, self::PREVIOUS_BUILTIN_DECISION_VERSION, self::LUA_DECISION_VERSION, self::PREVIOUS_SINGLE_LUA_DECISION_VERSION, self::MULTI_LUA_DECISION_VERSION, self::PREVIOUS_MULTI_LUA_DECISION_VERSION], true);
    }

    private function policyFor(string $name): PlayerPolicy
    {
        if ($name === 'lua') {
            return $this->luaPolicy ?? throw new \RuntimeException('Script Lua absent de cette ère.');
        }
        return new BuiltinPolicy($name);
    }

    private function saveLuaState(array &$state, string $id, LuaPolicy $policy): void
    {
        $script = $policy->state($id);
        $state['players'][$id]['luaMemory'] = $script['memory'];
        $state['players'][$id]['luaGoal'] = $script['goal'];
        $state['players'][$id]['luaMethod'] = $script['method'];
    }

    public static function decodeCombat(array $archive): array
    {
        if (($archive['format'] ?? null) !== self::COMBAT_ARCHIVE_FORMAT || !is_string($archive['payload'] ?? null)) {
            throw new \InvalidArgumentException('Format d’archive de combat inconnu.');
        }
        $binary = base64_decode($archive['payload'], true);
        $json = $binary === false ? false : gzdecode($binary);
        if ($json === false) {
            throw new \RuntimeException('Archive de combat corrompue.');
        }
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return ['request' => $document['request'], 'report' => $document['report'], 'event' => $archive['event']];
    }
}
