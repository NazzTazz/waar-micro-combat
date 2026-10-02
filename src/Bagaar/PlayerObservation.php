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
        $seenAt = static fn (?int $tick): ?string => $tick === null ? null
            : gmdate('Y-m-d\TH:i:s\Z', 1767225600 + $tick * 3600);
        foreach ($state['players'] as $otherId => $player) {
            if ($otherId !== $id && ($player['joinedTick'] ?? 0) < $state['tick']
                + (($state['manifest']['hostRuleVersion'] ?? null) === 'bagaar-host-rules/4' ? 1 : 0)) {
                $targets[] = ['id' => $otherId, 'name' => $player['name'] ?? $otherId, 'glory' => $player['glory'],
                    'kind' => 'player', 'lastSeenTick' => $player['lastSeenTick'] ?? null,
                    'lastSeenAt' => $seenAt($player['lastSeenTick'] ?? null)];
            }
        }
        foreach ($state['villages'] as $villageId => $village) {
            $targets[] = ['id' => $villageId, 'glory' => $village['glory'], 'kind' => 'village'];
        }
        usort($targets, static fn (array $a, array $b): int => [$a['glory'], $a['id']] <=> [$b['glory'], $b['id']]);
        $ranking = array_values(array_filter($targets, static fn (array $target): bool => $target['kind'] === 'player'));
        $ranking[] = ['id' => $id, 'name' => $self['name'] ?? $id, 'glory' => $self['glory'],
            'kind' => 'player', 'lastSeenTick' => $self['lastSeenTick'] ?? null,
            'lastSeenAt' => $seenAt($self['lastSeenTick'] ?? null)];
        usort($ranking, static fn (array $a, array $b): int => [$b['glory'], $a['id']] <=> [$a['glory'], $b['id']]);
        foreach ($ranking as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['rwaa'] = ($state['rwaa'] ?? null) === $row['id'];
        }
        unset($row);
        $gloryRank = 1;
        foreach ($ranking as $row) {
            if ($row['id'] === $id) {
                $gloryRank = $row['rank'];
                break;
            }
        }
        $active = [];
        foreach ($state['players'] as $otherId => $player) {
            if (($player['status'] ?? 'active') === 'active') {
                $active[] = ['id' => $otherId, 'glory' => $player['glory']];
            }
        }
        usort($active, static fn (array $a, array $b): int => [$b['glory'], $a['id']] <=> [$a['glory'], $b['id']]);
        $activeGloryRank = null;
        foreach ($active as $index => $row) {
            if ($row['id'] === $id) {
                $activeGloryRank = $index + 1;
                break;
            }
        }
        $metrics = $state['inspectionMetrics'][$id] ?? [];
        $record = $self['record'] ?? ['wins' => 0, 'draws' => 0, 'losses' => 0];
        $self['signals'] = DimensionlessSignals::from(
            $metrics['powerDestroyed'] ?? 0, $metrics['powerLost'] ?? 0,
            HostRules::armyValue($self['army'], $costs), $metrics['goldLooted'] ?? 0,
            $record['wins'] ?? 0, $record['draws'] ?? 0, $record['losses'] ?? 0,
            $self['mineLevel'] ?? 0, HostRules::mineProduction($self['mineLevel'] ?? 0),
            $self['glory'] ?? 0, $gloryRank, $activeGloryRank,
        );
        if (($state['traceDetached'] ?? false) === true) {
            $events = array_reverse($state['observationEvents'][$id] ?? []);
        } else {
            $events = [];
            for ($i = count($state['events']) - 1; $i >= 0; $i--) {
                $event = $state['events'][$i];
                if (($event['attacker'] ?? null) === $id || ($event['defender'] ?? null) === $id) {
                    $events[] = array_intersect_key($event, array_flip(['tick', 'attacker', 'defender', 'winner', 'surrender', 'report']));
                    if (count($events) >= 20) {
                        break;
                    }
                }
            }
        }
        $observation = ['tick' => $state['tick'], 'totalTicks' => $state['totalTicks'], 'spyRange' => $state['manifest']['spyRange'],
            'rwaa' => $state['rwaa'] ?? null, 'candidate' => $state['candidate'] ?? null,
            'self' => $self, 'targets' => $targets, 'ranking' => $ranking, 'reports' => $self['spies'],
            'events' => $events, 'costs' => $costs, 'attempts' => $attempts,
            'spyResults' => $state['spyResults'][$id] ?? []];
        if (($self['policy'] ?? null) === 'lua') {
            $observation['memory'] = $self['luaMemory'] ?? [];
        }
        return $observation;
    }
}
