<?php

namespace Waar\MicroCombat\Workshop;

final readonly class CohortRequestFactory
{
    public const POLICY_VERSION = 'wounded-capture-then-compress/3';
    public const SAMPLING_PROTOCOL = 'sha256-counter52-binomial-btrs/1';

    public static function consequenceContext(EngineProfile $profile): array
    {
        return ['policyVersion'=>self::POLICY_VERSION, 'samplingProtocol'=>self::SAMPLING_PROTOCOL,
            'lossCompressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent,
            'woundDamageThreshold'=>$profile->woundDamageThreshold];
    }

    public static function assertProvenance(array $actual, array $requested): void
    {
        foreach (['policyVersion'=>self::POLICY_VERSION, 'samplingProtocol'=>self::SAMPLING_PROTOCOL,
            'compressionPercent'=>$requested['compressionPercent'], 'capturePercent'=>$requested['capturePercent']] as $key=>$value) {
            if (($actual[$key] ?? null) !== $value) throw new \RuntimeException('Politique de conséquences du runtime incompatible : reconstruisez Rust et remesurez.');
        }
    }

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
            'consequences'=>['policyVersion'=>self::POLICY_VERSION, 'compressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent],
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
            'consequences'=>['policyVersion'=>self::POLICY_VERSION, 'compressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent],
            'scenarios'=>$scenarios,
        ];
    }
}
