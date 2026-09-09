<?php

namespace Waar\MicroCombat\Experiment;

final class NormalizedEllipseBoundaryPenalty
{
    /**
     * Returns the radial distance past the numerically accepted ellipse boundary.
     * A point accepted by AcceptanceZoneEvaluator always has a zero penalty.
     */
    public function fromSquaredDistance(float $normalizedSquaredDistance): float
    {
        if (!is_finite($normalizedSquaredDistance) || $normalizedSquaredDistance < 0) {
            throw new \InvalidArgumentException('Normalized squared distance must be a finite non-negative number.');
        }
        if ($normalizedSquaredDistance <= 1 + AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE) {
            return 0.0;
        }

        $boundary = 1 + AcceptanceZoneEvaluator::BOUNDARY_TOLERANCE;

        return ($normalizedSquaredDistance - $boundary)
            / (sqrt($normalizedSquaredDistance) + sqrt($boundary));
    }
}
