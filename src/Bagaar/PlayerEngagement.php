<?php

namespace Waar\MicroCombat\Bagaar;

/** Bounded memory of recent fights and deterministic player disengagement. */
final class PlayerEngagement
{
    public static function afterCombat(array $player, int $tick, bool $lost): array
    {
        if (($player['status'] ?? 'active') !== 'active') {
            return [$player, null];
        }
        $recent = array_values(array_filter($player['recentCombats'] ?? [],
            static fn (array $combat): bool => $combat['tick'] > $tick - 24));
        $recent[] = ['tick' => $tick, 'lost' => $lost];
        $player['recentCombats'] = $recent;
        $losses = count(array_filter($recent, static fn (array $combat): bool => $combat['lost']));
        if (count($recent) < 5 || $losses * 5 < count($recent) * 4) {
            return [$player, null];
        }
        $variant = hexdec(substr(hash('sha256', $player['id']), 0, 2));
        $player['recentCombats'] = [];
        if (($player['pauses'] ?? 0) >= 1 || $variant % 4 === 0) {
            $player['status'] = 'abandoned';
            $player['pauseUntil'] = null;
            return [$player, 'abandon'];
        }
        $player['status'] = 'pause';
        $player['pauses'] = ($player['pauses'] ?? 0) + 1;
        $player['pauseUntil'] = $tick + 48 + 24 * ($variant % 3);
        return [$player, 'pause'];
    }

    public static function resume(array $player, int $tick): array
    {
        if (($player['status'] ?? 'active') !== 'pause' || $tick < $player['pauseUntil']) {
            return [$player, null];
        }
        $player['status'] = 'active';
        $player['pauseUntil'] = null;
        $player['recentCombats'] = [];
        return [$player, 'return'];
    }
}
