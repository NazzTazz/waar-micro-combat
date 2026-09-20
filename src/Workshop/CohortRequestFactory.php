<?php

namespace Waar\MicroCombat\Workshop;

final readonly class CohortRequestFactory
{
    /**
     * @param array<string,int> $attacker
     * @param array<string,int> $defender
     * @param list<array<string,mixed>> $attackerModifiers
     * @param list<array<string,mixed>> $defenderModifiers
     * @return array<string,mixed>
     */
    public function combat(EngineProfile $profile, array $attacker, array $defender, int $seed, string $attackerWeather, string $defenderWeather,
        array $attackerModifiers = [], array $defenderModifiers = [], string $traceLevel = 'full'): array
    {
        return [
            'schemaVersion'=>'waar-combat-request/2',
            'ruleset'=>$profile->ruleset(),
            'seed'=>$seed,
            'traceLevel'=>$traceLevel,
            'attacker'=>['units'=>$attacker, 'modifiers'=>[...$attackerModifiers, ...$profile->weatherModifiers($attackerWeather, 'attacker')]],
            'defender'=>['units'=>$defender, 'modifiers'=>[...$defenderModifiers, ...$profile->weatherModifiers($defenderWeather, 'defender')]],
            'consequences'=>['compressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent],
        ];
    }

    /** @return array<string,mixed> */
    public function monotypes(EngineProfile $profile, string $weather, int $baseSeed, int $iterations): array
    {
        $scenarios = [];
        foreach (array_keys(EngineProfile::UNIT_COSTS) as $attacking) foreach (array_keys(EngineProfile::UNIT_COSTS) as $defending) {
            $scenarios[] = [
                'id'=>$attacking.'-vs-'.$defending,
                'attacker'=>['units'=>[$attacking=>intdiv(MonotypeMeasurementService::BUDGET, $profile->costs()[$attacking])], 'modifiers'=>$profile->weatherModifiers($weather, 'attacker')],
                'defender'=>['units'=>[$defending=>intdiv(MonotypeMeasurementService::BUDGET, $profile->costs()[$defending])], 'modifiers'=>$profile->weatherModifiers($weather, 'defender')],
            ];
        }
        return [
            'schemaVersion'=>'waar-combat-batch-request/2',
            'ruleset'=>$profile->ruleset(),
            'baseSeed'=>$baseSeed,
            'iterations'=>$iterations,
            'startIteration'=>0,
            'totalIterations'=>$iterations,
            'consequences'=>['compressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent],
            'scenarios'=>$scenarios,
        ];
    }
}
