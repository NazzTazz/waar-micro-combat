<?php

namespace Waar\MicroCombat\Experiment;

final class LegacyMonotypePitchCoordinates
{
    /** Descriptive coordinates only; no acceptance evaluation. */
    public function build(float $legacyWin, float $legacySurvivors, float $candidateWin, float $candidateSurvivors): array
    {
        foreach (func_get_args() as $value) {
            if (!is_finite($value) || $value < 0 || $value > 1) throw new \InvalidArgumentException('Expected finite ratio.');
        }
        $legacy = [100*$legacyWin, 2000*(1-$legacySurvivors)];
        $candidate = [100*$candidateWin, 100*(1-$candidateSurvivors)];
        // Fixed visual envelope in the displayed coordinate system, not measured uncertainty.
        $ellipse = [];
        for ($i=0; $i<=80; ++$i) {
            $angle = 2*M_PI*$i/80;
            $ellipse[] = [$legacy[0]+5*cos($angle), $legacy[1]+10*sin($angle)];
        }
        return ['legacy'=>$legacy,'candidate'=>$candidate,'legacyRawLoss'=>100*(1-$legacySurvivors),'ellipse'=>$ellipse,'deltaWin'=>$candidate[0]-$legacy[0],'deltaLoss'=>$candidate[1]-$legacy[1]];
    }
}
