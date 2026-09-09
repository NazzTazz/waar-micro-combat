<?php

namespace Waar\MicroCombat\Experiment;

final class AcceptanceZoneEvaluator
{
    public const BOUNDARY_TOLERANCE = 1e-12;

    /** @param array<string, mixed> $zone */
    public function evaluate(?float $x, ?float $y, array $zone): string
    {
        if (null === $x || null === $y) {
            return 'not-applicable';
        }
        $distance = $this->normalizedSquaredDistance($x, $y, $zone);
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return 'outside';
        }

        return $distance <= 1 + self::BOUNDARY_TOLERANCE ? 'inside' : 'outside';
    }

    /** @param array<string, mixed> $zone */
    public function normalizedSquaredDistance(float $x, float $y, array $zone): float
    {
        $centerX = $zone['center']['x'] ?? null;
        $centerY = $zone['center']['y'] ?? null;
        $radiusX = $zone['radii']['x'] ?? null;
        $radiusY = $zone['radii']['y'] ?? null;
        foreach ([$x, $y, $centerX, $centerY, $radiusX, $radiusY] as $value) {
            if (!is_float($value) && !is_int($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Acceptance-zone coordinates must be finite numbers.');
            }
        }
        if ($centerX < 0 || $centerX > 1 || $centerY < 0 || $centerY > 1 || $radiusX <= 0 || $radiusY <= 0) {
            throw new \InvalidArgumentException('Acceptance-zone center or radii are outside their valid domain.');
        }
        return (($x - $centerX) / $radiusX) ** 2 + (($y - $centerY) / $radiusY) ** 2;
    }
}
