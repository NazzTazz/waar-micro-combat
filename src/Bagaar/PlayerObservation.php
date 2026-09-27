<?php

namespace Waar\MicroCombat\Bagaar;

/** The only information a player policy receives about its opponents. */
final class PlayerObservation
{
    public static function fromState(array $state, string $id, array $costs, array $attempts = []): array
    {
        $self = $state['players'][$id] ?? null;
        if (!is_array($self)) {
            throw new \InvalidArgumentException('Compte inconnu.');
        }
        $targets = [];
        foreach ($state['players'] as $otherId => $player) {
            if ($otherId !== $id) {
                $targets[] = ['id' => $otherId, 'glory' => $player['glory'], 'kind' => 'player'];
            }
        }
        foreach ($state['villages'] as $villageId => $village) {
            $targets[] = ['id' => $villageId, 'glory' => $village['glory'], 'kind' => 'village'];
        }
        usort($targets, static fn (array $a, array $b): int => [$a['glory'], $a['id']] <=> [$b['glory'], $b['id']]);
        $events = [];
        foreach (array_reverse($state['events']) as $event) {
            if (($event['attacker'] ?? null) === $id || ($event['defender'] ?? null) === $id) {
                $events[] = array_intersect_key($event, array_flip(['tick', 'attacker', 'defender', 'winner', 'surrender']));
                if (count($events) >= 20) {
                    break;
                }
            }
        }
        return ['tick' => $state['tick'], 'totalTicks' => $state['totalTicks'], 'spyRange' => $state['manifest']['spyRange'],
            'self' => $self, 'targets' => $targets, 'reports' => $self['spies'],
            'events' => $events, 'costs' => $costs, 'attempts' => $attempts];
    }
}
