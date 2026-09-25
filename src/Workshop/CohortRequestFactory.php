<?php

namespace Waar\MicroCombat\Workshop;

final readonly class CohortRequestFactory
{
    public const POLICY_VERSION = 'wounded-capture-then-compress/4';
    public const SAMPLING_PROTOCOL = 'sha256-binomial-tree/1';
    public const STOCHASTIC_VERSION = 'sha256-binomial-tree/1';

    public static function consequenceContext(EngineProfile $profile): array
    {
        return ['stochasticEngineVersion'=>self::STOCHASTIC_VERSION, 'armyIdentityConvention'=>'A/B', 'policyVersion'=>self::POLICY_VERSION, 'samplingProtocol'=>self::SAMPLING_PROTOCOL,
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

    public static function assertRandomProvenance(array $actual, array $identities): void
    {
        if (($actual['stochasticEngineVersion']??null)!==self::STOCHASTIC_VERSION
            || ($actual['armyIdentities']['attacker']??null)!==($identities['attacker']??null)
            || ($actual['armyIdentities']['defender']??null)!==($identities['defender']??null)) {
            throw new \RuntimeException('Protocole aléatoire ou identités A/B du runtime incompatibles : reconstruisez Rust et remesurez.');
        }
    }

    public static function assertBatchRandomProvenance(array $batch, array $requestedScenarios): void
    {
        $actual=array_column($batch['scenarios']??[],null,'id');
        foreach($requestedScenarios as $scenario)self::assertRandomProvenance([
            'stochasticEngineVersion'=>$batch['stochasticEngineVersion']??null,
            'armyIdentities'=>$actual[$scenario['id']]['armyIdentities']??null,
        ],$scenario['armyIdentities']);
    }

    /**
     * @param array<string,int> $attacker
     * @param array<string,int> $defender
     * @param list<array<string,mixed>> $attackerModifiers
     * @param list<array<string,mixed>> $defenderModifiers
     * @return array<string,mixed>
     */
    public function combat(EngineProfile $profile, array $attacker, array $defender, int $seed, string $attackerWeather, string $defenderWeather,
        array $attackerModifiers = [], array $defenderModifiers = [], string $traceLevel = 'full', string $attackerIdentity = 'A', string $defenderIdentity = 'B'): array
    {
        return [
            'schemaVersion'=>'waar-combat-request/2',
            'stochasticEngineVersion'=>self::STOCHASTIC_VERSION,
            'armyIdentities'=>['attacker'=>$attackerIdentity,'defender'=>$defenderIdentity],
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
                'armyIdentities'=>['attacker'=>'A','defender'=>'B'],
                'attacker'=>['units'=>[$attacking=>intdiv(MonotypeMeasurementService::BUDGET, $profile->costs()[$attacking])], 'modifiers'=>$profile->weatherModifiers($weather, 'attacker')],
                'defender'=>['units'=>[$defending=>intdiv(MonotypeMeasurementService::BUDGET, $profile->costs()[$defending])], 'modifiers'=>$profile->weatherModifiers($weather, 'defender')],
            ];
        }
        return [
            'schemaVersion'=>'waar-combat-batch-request/2',
            'stochasticEngineVersion'=>self::STOCHASTIC_VERSION,
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
