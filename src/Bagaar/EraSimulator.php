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
    private CohortRuntime $runtime;
    private CohortRequestFactory $requests;

    public function __construct(private readonly EngineProfile $profile, ?CohortRuntime $runtime = null)
    {
        $this->runtime = $runtime ?? new ProcessCohortRuntime();
        $this->requests = new CohortRequestFactory();
    }

    /** @param list<array{id:string,policy:string,name?:string,activity?:string,aggressionPercent?:int,soldierParadigm?:bool}> $accounts */
    public function start(int $seed, int $totalTicks, array $accounts): array
    {
        if ($seed < 0 || $seed > 2147483647 || $totalTicks < 1 || $totalTicks > 1440 || count($accounts) < 2 || count($accounts) > 20) {
            throw new \InvalidArgumentException('Seed, durée ou nombre de comptes invalide.');
        }
        $players = [];
        foreach ($accounts as $entry) {
            $id = $entry['id'] ?? null;
            $policy = $entry['policy'] ?? null;
            $name = $entry['name'] ?? $id;
            $activity = $entry['activity'] ?? 'all-day';
            $aggression = $entry['aggressionPercent'] ?? 100;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || isset($players[$id]) || !in_array($policy, BuiltinPolicy::NAMES, true)) {
                throw new \InvalidArgumentException('Identifiant ou profil joueur invalide.');
            }
            if (!is_string($name) || trim($name) === '' || strlen($name) > 64
                || !is_string($activity) || !in_array($activity, PlayerSchedule::WINDOWS, true)
                || !is_int($aggression) || $aggression < 60 || $aggression > 140) {
                throw new \InvalidArgumentException('Identité ou rythme joueur invalide.');
            }
            $players[$id] = AccountRules::initial($id, $policy);
            $players[$id]['name'] = $name;
            $players[$id]['activity'] = $activity;
            $players[$id]['aggressionPercent'] = $aggression;
            if ($policy === 'grenouille') {
                $players[$id]['soldierParadigm'] = (bool)($entry['soldierParadigm'] ?? false);
            }
        }
        ksort($players);
        foreach ($players as $id => &$player) {
            if ($player['policy'] === 'fermier') {
                $player['fridges'] = array_slice(array_values(array_diff(array_keys($players), [$id])), 0, 2);
            }
        }
        unset($player);
        return ['schemaVersion' => 'waar-bagaar-era/1', 'manifest' => [
            'seed' => $seed, 'profileFingerprint' => $this->profile->semanticFingerprint(),
            'runtime' => $this->runtime->provenance(), 'attackRange' => HostRules::ATTACK_RANGE,
            'spyRange' => 10, 'weatherConvention' => 'one-weather-both-sides/1',
            'decisionVersion' => 'bagaar-builtin-policies/2', 'hostRuleVersion' => 'bagaar-host-rules/1',
        ], 'tick' => 0, 'totalTicks' => $totalTicks, 'players' => $players,
            'villages' => [], 'villageAttacks' => [], 'candidate' => null, 'candidateHours' => 0,
            'rwaa' => null, 'rwaaPv' => 0, 'events' => [], 'combats' => [], 'frames' => []];
    }

    public function advance(array $state, int $steps = 1): array
    {
        if (($state['schemaVersion'] ?? null) !== 'waar-bagaar-era/1'
            || ($state['manifest']['profileFingerprint'] ?? null) !== $this->profile->semanticFingerprint()
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
        foreach ($state['players'] as $id => $player) {
            $state['players'][$id] = AccountRules::hourly($player, 40 + self::random($master, $tick, 'hospital:'.$id) % 41);
        }
        foreach ($state['villages'] as $id => $village) {
            $caps = VillageRules::caps($village['glory'], $state['players'], $this->profile->costs());
            $state['villages'][$id] = VillageRules::refill($village, $caps);
            $state['villages'][$id]['defenses'] = 9999;
        }
        $state['villageAttacks'] = [];
        $this->checkRwaa($state);
        $this->ensureVillages($state);
        foreach (array_keys($state['players']) as $id) {
            if (!PlayerSchedule::isActive($state['players'][$id]['activity'], $tick)) {
                continue;
            }
            $attempts = [];
            $policy = new BuiltinPolicy($state['players'][$id]['policy']);
            for ($actionIndex = 0; $actionIndex < 16; $actionIndex++) {
                $view = PlayerObservation::fromState($state, $id, $this->profile->costs(), $attempts);
                $action = $policy->next($view);
                if ($action === null) {
                    break;
                }
                $attempts[] = $action;
                try {
                    $this->act($state, $id, $action, $weather, $actionIndex);
                } catch (\DomainException $error) {
                    $state['events'][] = ['tick' => $tick, 'type' => 'rejected', 'actor' => $id,
                        'action' => $action['type'], 'reason' => $error->getMessage()];
                }
            }
        }
        $points = [];
        foreach ($state['players'] as $id => $player) {
            $value = HostRules::armyValue($player['army'], $this->profile->costs());
            $state['players'][$id]['peakArmyGold'] = max($player['peakArmyGold'], $value);
            $points[] = ['id' => $id, 'name' => $player['name'], 'kind' => 'player', 'policy' => $player['policy'],
                'armyGold' => $value, 'army' => $player['army'], 'glory' => $player['glory'], 'gold' => $player['gold'],
                'mineLevel' => $player['mineLevel'], 'mineProduction' => HostRules::mineProduction($player['mineLevel']),
                'record' => $player['record'], 'activity' => $player['activity'], 'aggressionPercent' => $player['aggressionPercent']];
        }
        $villages = [];
        foreach ($state['villages'] as $id => $village) {
            $villages[] = ['id' => $id, 'kind' => 'village', 'policy' => 'village',
                'armyGold' => HostRules::armyValue($village['army'], $this->profile->costs()),
                'glory' => $village['glory'], 'gold' => $village['gold']];
        }
        $state['frames'][] = ['tick' => $tick, 'weather' => $weather, 'points' => $points,
            'villages' => $villages,
            'candidate' => $state['candidate'], 'candidateHours' => $state['candidateHours'],
            'rwaa' => $state['rwaa'], 'rwaaPv' => $state['rwaaPv'], 'eventCount' => count($state['events'])];
        return $state;
    }

    private function act(array &$state, string $id, array $action, string $weather, int $index): void
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
            case 'phase':
                if ($player['policy'] !== 'ascenseur' || !in_array($action['value'] ?? null, ['raid', 'surrender'], true)) {
                    throw new \DomainException('Phase de profil invalide.');
                }
                $state['players'][$id]['cyclePhase'] = $action['value'];
                break;
            case 'spy':
                $target = $state['players'][$action['target']] ?? null;
                if ($target === null) {
                    throw new \DomainException('Cible d’espionnage inconnue.');
                }
                $state['players'][$id] = AccountRules::spy($player, $target, $state['manifest']['spyRange']);
                $state['players'][$id]['spies'][$target['id']]['tick'] = $state['tick'];
                break;
            case 'attack':
                $this->attack($state, $id, (string)$action['target'], $weather, $index);
                return;
            default:
                throw new \DomainException('Action inconnue.');
        }
        $state['events'][] = ['tick' => $state['tick'], 'type' => $type, 'actor' => $id];
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
        if (!HostRules::canAttack($attacker['glory'], $defender['glory'], $attacker['attacks'], $defender['defenses'])
            || array_sum($attacker['army']) === 0 || array_sum($defender['army']) === 0) {
            throw new \DomainException('Portée, quota ou armée insuffisante.');
        }
        $combatSeed = self::random($state['manifest']['seed'], $state['tick'], 'combat:'.count($state['combats']).':'.$index);
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
            ? $lower + self::random($state['manifest']['seed'], $state['tick'], 'loot:'.count($state['combats'])) % ($upper - $lower + 1) : 0;
        $result = CombatTransition::apply($attacker, $defender, $report, $loot, $village);
        $state['players'][$id] = $result['attacker'];
        $state['players'][$id]['record'][$result['event']['winner'] === 'attacker' ? 'wins' : ($result['event']['winner'] === null ? 'draws' : 'losses')]++;
        if ($village) {
            $state['villages'][$targetId] = $result['defender'];
            $state['villageAttacks'][$id][$targetId] = ($state['villageAttacks'][$id][$targetId] ?? 0) + 1;
        } else {
            $state['players'][$targetId] = $result['defender'];
            $state['players'][$targetId]['record'][$result['event']['winner'] === 'defender' ? 'wins' : ($result['event']['winner'] === null ? 'draws' : 'losses')]++;
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
        $payload = json_encode(['request' => $request, 'report' => $report], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($payload, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Compression de l’archive de combat impossible.');
        }
        $state['combats'][] = ['format' => self::COMBAT_ARCHIVE_FORMAT,
            'payload' => base64_encode($compressed), 'event' => $event];
        foreach ([$id, $targetId] as $participant) {
            if (!isset($state['players'][$participant])
                || !PlayerSchedule::isActive($state['players'][$participant]['activity'], $state['tick'])) {
                continue;
            }
            $policy = new BuiltinPolicy($state['players'][$participant]['policy']);
            $reaction = $policy->afterCombat(PlayerObservation::fromState($state, $participant, $this->profile->costs()));
            if ($reaction !== null) {
                try {
                    $this->act($state, $participant, $reaction, $weather, $index);
                } catch (\DomainException $error) {
                    $state['events'][] = ['tick' => $state['tick'], 'type' => 'rejected', 'actor' => $participant,
                        'action' => $reaction['type'], 'reason' => $error->getMessage()];
                }
            }
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
        $ranking = $state['players'];
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
