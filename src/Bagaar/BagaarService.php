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
        $accounts = $request['accounts'] ?? self::defaultAccounts($soldierFrog);
        if (!is_int($seed) || !is_int($totalTicks) || !is_array($accounts) || !array_is_list($accounts)) {
            throw new \InvalidArgumentException('Paramètres de simulation invalides.');
        }
        $state = (new EraSimulator($profile, $this->runtime ?? new StreamingCohortRuntime()))->start($seed, $totalTicks, $accounts);
        $state['archiveDetached'] = true;
        $id = $this->runs->create(['profile' => $profileInput, 'state' => $state]);
        return ['runId' => $id, 'manifest' => $state['manifest'], 'tick' => 0,
            'totalTicks' => $totalTicks, 'accounts' => array_map(static fn (array $player): array =>
                ['id' => $player['id'], 'name' => $player['name'], 'policy' => $player['policy'],
                    'activity' => $player['activity'], 'aggressionPercent' => $player['aggressionPercent']], array_values($state['players']))];
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
            $simulator = new EraSimulator($profile, $this->runtime ?? new StreamingCohortRuntime());
            $previousFrames = count($document['state']['frames']);
            $previousEvents = count($document['state']['events']);
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
        $frameCount = count($state['frames']);
        if ($frameOffset > $frameCount) {
            throw new \InvalidArgumentException('Offset de trame invalide.');
        }
        $end = min($frameCount, $frameOffset + $limit);
        $eventOffset = $frameOffset === 0 ? 0 : $state['frames'][$frameOffset - 1]['eventCount'];
        $eventEnd = $end === 0 ? 0 : $state['frames'][$end - 1]['eventCount'];
        return ['runId' => $id, 'tick' => $state['tick'], 'totalTicks' => $state['totalTicks'],
            'done' => $state['tick'] >= $state['totalTicks'], 'manifest' => $state['manifest'],
            'frames' => array_slice($state['frames'], $frameOffset, $end - $frameOffset),
            'events' => array_slice($state['events'], $eventOffset, $eventEnd - $eventOffset),
            'eventCount' => count($state['events']), 'combatCount' => $state['combatCount'] ?? count($state['combats']),
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
            'frames' => array_slice($state['frames'], $frameOffset),
            'events' => array_slice($state['events'], $eventOffset),
            'eventCount' => count($state['events']), 'combatCount' => $state['combatCount'] ?? count($state['combats'])];
    }

    private static function defaultAccounts(bool $soldierFrog): array
    {
        $groups = [
            'rageux' => [['axel', 'Axel'], ['bruno', 'Bruno'], ['chloe', 'Chloé'], ['dorian', 'Dorian']],
            'grenouille' => [['eloise', 'Éloïse'], ['farid', 'Farid'], ['gaelle', 'Gaëlle'], ['hugo', 'Hugo']],
            'ascenseur' => [['iris', 'Iris'], ['jules', 'Jules'], ['kamel', 'Kamel'], ['lea', 'Léa']],
            'fermier' => [['malo', 'Malo'], ['nina', 'Nina'], ['oscar', 'Oscar'], ['pauline', 'Pauline']],
            'scripteur' => [['quentin', 'Quentin'], ['romane', 'Romane'], ['sami', 'Sami'], ['tess', 'Tess']],
        ];
        $activities = ['all-day', 'office', 'evening', 'early'];
        $aggressions = [105, 80, 120, 95];
        $accounts = [];
        foreach ($groups as $policy => $members) {
            foreach ($members as $index => [$id, $name]) {
                $accounts[] = ['id' => $id, 'name' => $name, 'policy' => $policy,
                    'activity' => $activities[$index], 'aggressionPercent' => $aggressions[$index],
                    ...($policy === 'grenouille' ? ['soldierParadigm' => $soldierFrog] : [])];
            }
        }
        return $accounts;
    }
}
