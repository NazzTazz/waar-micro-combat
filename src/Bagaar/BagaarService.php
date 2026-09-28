<?php

namespace Waar\MicroCombat\Bagaar;

use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\CohortRuntime;

/** HTTP-facing orchestration; all decisions and transitions remain in pure services. */
final class BagaarService
{
    public function __construct(private readonly RunStore $runs = new RunStore(), private readonly ?CohortRuntime $runtime = null)
    {
    }

    public function start(array $request): array
    {
        $profileInput = $request['profile'] ?? null;
        if (!is_array($profileInput)) {
            throw new \InvalidArgumentException('Preset de combat requis.');
        }
        $profile = EngineProfile::fromArray($profileInput);
        $seed = $request['seed'] ?? 42;
        $totalTicks = $request['totalTicks'] ?? 1440;
        $soldierFrog = $request['soldierFrog'] ?? false;
        if (!is_bool($soldierFrog)) {
            throw new \InvalidArgumentException('Option des Grenouilles invalide.');
        }
        $script = $request['luaScript'] ?? null;
        if ($script !== null && !is_string($script)) {
            throw new \InvalidArgumentException('Script Lua invalide.');
        }
        $scripts = $request['luaScripts'] ?? null;
        if ($scripts !== null && (!is_array($scripts) || $scripts === [] || array_is_list($scripts)
            || count($scripts) > 24)) {
            throw new \InvalidArgumentException('Catalogue de scripts Lua invalide.');
        }
        if ($scripts !== null) {
            foreach ($scripts as $key => $source) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $key)
                    || !is_string($source) || $source === '' || strlen($source) > 16_384
                    || preg_match('//u', $source) !== 1) {
                    throw new \InvalidArgumentException('Catalogue de scripts Lua invalide.');
                }
            }
            if ($script !== null) {
                if (isset($scripts['comptable'])) {
                    throw new \InvalidArgumentException('Deux scripts pour Le comptable.');
                }
                $scripts['comptable'] = $script;
            }
            ksort($scripts);
        }
        $accounts = $request['accounts'] ?? self::defaultAccounts($soldierFrog,
            $script !== null || isset($scripts['comptable']), $scripts === null ? [] : array_keys($scripts));
        if (!is_int($seed) || !is_int($totalTicks) || !is_array($accounts) || !array_is_list($accounts)) {
            throw new \InvalidArgumentException('Paramètres de simulation invalides.');
        }
        $expanded = [];
        if ($scripts !== null) {
            foreach ($accounts as &$account) {
                if (($account['policy'] ?? null) !== 'lua') {
                    continue;
                }
                $id = $account['id'] ?? null;
                $key = $account['scriptKey'] ?? $id;
                if (!is_string($id) || !is_string($key) || !isset($scripts[$key])) {
                    throw new \InvalidArgumentException('Script Lua manquant pour un compte.');
                }
                $account['scriptKey'] = $key;
                $expanded[$id] = $scripts[$key];
            }
            unset($account);
            if ($expanded === []) {
                throw new \InvalidArgumentException('Aucun compte contrôlé par les scripts Lua.');
            }
            ksort($expanded);
        }
        $luaPolicy = $scripts !== null ? new LuaPolicy($expanded) : ($script === null ? null : new LuaPolicy($script));
        $state = (new EraSimulator($profile, $this->runtime ?? new StreamingCohortRuntime(), $luaPolicy, $scripts ?? []))
            ->start($seed, $totalTicks, $accounts);
        if ($script !== null) {
            $state['manifest']['luaScriptSha256'] = hash('sha256', $script);
        }
        if ($scripts !== null) {
            $state['manifest']['luaScriptsSha256'] = hash('sha256', json_encode($scripts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        $state['archiveDetached'] = true;
        $state['traceDetached'] = true;
        $state['frameCount'] = 0;
        $state['eventCount'] = 0;
        $state['observationEvents'] = [];
        $id = $this->runs->create(['profile' => $profileInput, 'state' => $state,
            ...($script === null ? [] : ['luaScript' => $script]),
            ...($scripts === null ? [] : ['luaScripts' => $scripts])]);
        return ['runId' => $id, 'manifest' => $state['manifest'], 'tick' => 0,
            'totalTicks' => $totalTicks, 'accounts' => array_map(static fn (array $player): array =>
                ['id' => $player['id'], 'name' => $player['name'], 'policy' => $player['policy'],
                    'activity' => $player['activity'], 'aggressionPercent' => $player['aggressionPercent'],
                    'scriptKey' => $player['scriptKey'] ?? null], array_values($state['players']))];
    }

    public function advance(array $request): array
    {
        $id = $request['runId'] ?? null;
        $steps = $request['steps'] ?? 1;
        if (!is_string($id) || !is_int($steps) || $steps < 1 || $steps > 24) {
            throw new \InvalidArgumentException('Simulation ou nombre de ticks invalide.');
        }
        $previousFrames = 0;
        $previousEvents = 0;
        $document = $this->runs->update($id, function (array $document) use ($steps, &$previousFrames, &$previousEvents): array {
            $profile = EngineProfile::fromArray($document['profile']);
            $script = $document['luaScript'] ?? null;
            if ($script !== null && (!is_string($script) || hash('sha256', $script) !== ($document['state']['manifest']['luaScriptSha256'] ?? null))) {
                throw new \RuntimeException('Script Lua de l’ère altéré.');
            }
            $scripts = $document['luaScripts'] ?? null;
            if ($scripts !== null && (!is_array($scripts) || hash('sha256', json_encode($scripts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
                !== ($document['state']['manifest']['luaScriptsSha256'] ?? null))) {
                throw new \RuntimeException('Catalogue Lua de l’ère altéré.');
            }
            $expanded = [];
            if ($scripts !== null) {
                foreach ($document['state']['players'] as $account) {
                    if (($account['policy'] ?? null) === 'lua' && ($account['status'] ?? 'active') !== 'abandoned') {
                        $key = $account['scriptKey'] ?? $account['id'];
                        if (!isset($scripts[$key])) {
                            throw new \RuntimeException('Script Lua du compte introuvable.');
                        }
                        $expanded[$account['id']] = $scripts[$key];
                    }
                }
                ksort($expanded);
            }
            $simulator = new EraSimulator($profile, $this->runtime ?? new StreamingCohortRuntime(),
                $scripts !== null ? new LuaPolicy($expanded) : ($script === null ? null : new LuaPolicy($script)), $scripts ?? []);
            $previousFrames = $document['state']['frameCount'] ?? count($document['state']['frames']);
            $previousEvents = $document['state']['eventCount'] ?? count($document['state']['events']);
            $document['state'] = $simulator->advance($document['state'], $steps);
            return $document;
        });
        return $this->summary($id, $document['state'], $previousFrames, $previousEvents);
    }

    public function resume(array $request): array
    {
        $id = $request['runId'] ?? null;
        $frameOffset = $request['frameOffset'] ?? 0;
        $limit = $request['limit'] ?? 50;
        if (!is_string($id) || !is_int($frameOffset) || $frameOffset < 0
            || !is_int($limit) || $limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Simulation ou pagination invalide.');
        }
        $state = $this->runs->read($id)['state'];
        $detached = ($state['traceDetached'] ?? false) === true;
        $frameCount = $detached ? $state['frameCount'] : count($state['frames']);
        if ($frameOffset > $frameCount) {
            throw new \InvalidArgumentException('Offset de trame invalide.');
        }
        $end = min($frameCount, $frameOffset + $limit);
        $page = $detached ? $this->runs->readTrace($id, 'frames', $frameOffset, $end - $frameOffset)
            : array_slice($state['frames'], $frameOffset, $end - $frameOffset);
        $previous = $frameOffset === 0 ? null : ($detached
            ? $this->runs->readTrace($id, 'frames', $frameOffset - 1, 1)[0] : $state['frames'][$frameOffset - 1]);
        $eventOffset = $previous['eventCount'] ?? 0;
        $eventEnd = $page === [] ? $eventOffset : $page[count($page) - 1]['eventCount'];
        return ['runId' => $id, 'tick' => $state['tick'], 'totalTicks' => $state['totalTicks'],
            'done' => $state['tick'] >= $state['totalTicks'], 'manifest' => $state['manifest'],
            'frames' => $page,
            'events' => $detached ? $this->runs->readTrace($id, 'events', $eventOffset, $eventEnd - $eventOffset)
                : array_slice($state['events'], $eventOffset, $eventEnd - $eventOffset),
            'eventCount' => $state['eventCount'] ?? count($state['events']), 'combatCount' => $state['combatCount'] ?? count($state['combats']),
            'nextFrameOffset' => $end, 'hasMoreFrames' => $end < $frameCount];
    }

    public function combat(array $request): array
    {
        $id = $request['runId'] ?? null;
        $index = $request['index'] ?? null;
        if (!is_string($id) || !is_int($index) || $index < 0) {
            throw new \InvalidArgumentException('Combat requis.');
        }
        return EraSimulator::decodeCombat($this->runs->readCombat($id, $index));
    }

    private function summary(string $id, array $state, int $frameOffset, int $eventOffset): array
    {
        return ['runId' => $id, 'tick' => $state['tick'], 'totalTicks' => $state['totalTicks'],
            'done' => $state['tick'] >= $state['totalTicks'], 'manifest' => $state['manifest'],
            'frames' => ($state['traceDetached'] ?? false) ? $state['frames'] : array_slice($state['frames'], $frameOffset),
            'events' => ($state['traceDetached'] ?? false) ? $state['events'] : array_slice($state['events'], $eventOffset),
            'eventCount' => $state['eventCount'] ?? count($state['events']), 'combatCount' => $state['combatCount'] ?? count($state['combats'])];
    }

    private static function defaultAccounts(bool $soldierFrog, bool $withLua = false, array $scriptKeys = []): array
    {
        $groups = [
            'rageux' => [['axel', 'Axel'], ['bruno', 'Bruno'], ['chloe', 'Chloé'], ['dorian', 'Dorian']],
            'grenouille' => [['eloise', 'Éloïse'], ['farid', 'Farid'], ['gaelle', 'Gaëlle'], ['hugo', 'Hugo']],
            'ascenseur' => [['iris', 'Iris'], ['jules', 'Jules'], ['kamel', 'Kamel'], ['lea', 'Léa']],
            'fermier' => [['malo', 'Malo'], ['nina', 'Nina'], ['oscar', 'Oscar'], ['pauline', 'Pauline']],
            'scripteur' => [['quentin', 'Quentin'], ['romane', 'Romane'], ['sami', 'Sami'], ['hacker', 'Hacker']],
            'casual' => [['ugo', 'Ugo'], ['victoire', 'Victoire'], ['william', 'William'], ['zoe', 'Zoé']],
        ];
        $activities = ['all-day', 'office', 'evening', 'early'];
        $aggressions = [105, 80, 120, 95];
        $accounts = [];
        foreach ($groups as $policy => $members) {
            foreach ($members as $index => [$id, $name]) {
                if ($withLua && $id === 'quentin') {
                    $accounts[] = ['id' => 'comptable', 'name' => 'Le comptable', 'policy' => 'lua',
                        'activity' => 'all-day', 'aggressionPercent' => 100,
                        ...($scriptKeys === [] ? [] : ['scriptKey' => 'comptable'])];
                    continue;
                }
                $scripted = in_array($policy, $scriptKeys, true);
                $accounts[] = ['id' => $id, 'name' => $name, 'policy' => $scripted ? 'lua' : $policy,
                    'activity' => $policy === 'casual' ? ['casual-morning', 'casual-noon', 'casual-evening', 'casual-night'][$index] : $activities[$index],
                    'aggressionPercent' => $aggressions[$index],
                    ...($scripted ? ['scriptKey' => $policy] : []),
                    ...($id === 'hacker' ? ['hacker' => true] : []),
                    ...($id === 'zoe' ? ['protester' => true] : []),
                    ...($policy === 'grenouille' ? ['soldierParadigm' => $soldierFrog] : [])];
            }
        }
        return $accounts;
    }
}
