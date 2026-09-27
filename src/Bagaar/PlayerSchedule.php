<?php

namespace Waar\MicroCombat\Bagaar;

/** An account acts during its local play hours; the economy still ticks all day. */
final class PlayerSchedule
{
    public const WINDOWS = ['all-day', 'office', 'evening', 'early'];

    public static function isActive(string $window, int $tick): bool
    {
        if (!in_array($window, self::WINDOWS, true) || $tick < 1) {
            throw new \InvalidArgumentException('Plage horaire ou tick invalide.');
        }
        $hour = ($tick - 1) % 24;
        return match ($window) {
            'all-day' => true,
            'office' => $hour >= 9 && $hour < 17,
            'evening' => $hour >= 17 && $hour < 24,
            'early' => $hour >= 6 && $hour < 14,
        };
    }
}
