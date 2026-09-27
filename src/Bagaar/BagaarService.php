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
        $accounts = $request['accounts'] ?? self::defaultAccounts();
        if (!is_int($seed) || !is_int($totalTicks) || !is_array($accounts) || !array_is_list($accounts)) {
            throw new \InvalidArgumentException('Paramètres de simulation invalides.');
        }
        $state = (new EraSimulator($profile, $this->runtime ?? new StreamingCohortRuntime()))->start($seed, $totalTicks, $accounts);
        $id = $this->runs->create(['profile' => $profileInput, 'state' => $state]);
        return ['runId' => $id, 'manifest' => $state['manifest'], 'tick' => 0,
            'totalTicks' => $totalTicks, 'accounts' => array_map(static fn (array $player): array =>
                ['id' => $player['id'], 'policy' => $player['policy']], array_values($state['players']))];
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
        if (!is_string($id)) {
            throw new \InvalidArgumentException('Simulation requise.');
        }
        $state = $this->runs->read($id)['state'];
        return $this->summary($id, $state, 0, 0);
    }

    public function combat(array $request): array
    {
        $id = $request['runId'] ?? null;
        $index = $request['index'] ?? null;
        if (!is_string($id) || !is_int($index) || $index < 0) {
            throw new \InvalidArgumentException('Combat requis.');
        }
        $state = $this->runs->read($id)['state'];
        if (!isset($state['combats'][$index])) {
            throw new \RuntimeException('Combat introuvable.', 404);
        }
        return EraSimulator::decodeCombat($state['combats'][$index]);
    }

    private function summary(string $id, array $state, int $frameOffset, int $eventOffset): array
    {
        return ['runId' => $id, 'tick' => $state['tick'], 'totalTicks' => $state['totalTicks'],
            'done' => $state['tick'] >= $state['totalTicks'], 'manifest' => $state['manifest'],
            'frames' => array_slice($state['frames'], $frameOffset),
            'events' => array_slice($state['events'], $eventOffset),
            'eventCount' => count($state['events']), 'combatCount' => count($state['combats'])];
    }

    private static function defaultAccounts(): array
    {
        return [
            ['id' => 'rageux', 'policy' => 'rageux'],
            ['id' => 'grenouille', 'policy' => 'grenouille', 'soldierParadigm' => false],
            ['id' => 'ascenseur', 'policy' => 'ascenseur'],
            ['id' => 'fermier', 'policy' => 'fermier'],
            ['id' => 'scripteur', 'policy' => 'scripteur'],
        ];
    }
}
