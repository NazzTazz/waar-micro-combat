<?php

namespace App\Game\Combat;

/** Reconstruct a public (initially healthy) request using only a saved report. */
final class CombatReplay
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function request(array $report): array
    {
        $result = $report['result'] ?? [];
        if (($result['schemaVersion'] ?? null) !== 'waar-combat-result/2'
            || !is_array($result['ruleset'] ?? null) || !is_array($result['initialArmies'] ?? null)
            || !is_array($result['snapshot'] ?? null)) {
            throw new \InvalidArgumentException('Report lacks the complete replay inputs.');
        }
        $snapshot = $result['snapshot'];
        $armies = $result['initialArmies'];
        $hash = hash('sha256', CanonicalJson::encode([
            'ruleset' => $result['ruleset'], 'snapshot' => $snapshot, 'armies' => $armies,
        ]));
        if ($hash !== ($result['replayHash'] ?? null)) {
            throw new \InvalidArgumentException('Replay inputs do not match the report hash.');
        }
        $request = [
            'schemaVersion' => CombatEngine::REQUEST_SCHEMA,
            'ruleset' => $result['ruleset'],
            'seed' => $snapshot['seed'],
            'traceLevel' => $snapshot['traceLevel'],
        ];
        $protocol=$snapshot['stochasticEngineVersion']??null;
        if($protocol===\App\Game\Random\AddressedRandom::VERSION){
            $request['stochasticEngineVersion']=$protocol;
            $request['armyIdentities']=$snapshot['armyIdentities']??null;
        } elseif($protocol!==\App\Game\Random\StochasticEngineVersion::Lcg31NormalApproximationV1->value || array_key_exists('armyIdentities',$snapshot)) {
            throw new \InvalidArgumentException('Unsupported replay stochastic protocol.');
        }
        foreach (['attacker', 'defender'] as $side) {
            $request[$side] = ['units' => $armies[$side], 'modifiers' => $snapshot['prepared'][$side]['modifiers']];
        }
        if (isset($report['consequences'])) {
            $settings = $report['consequences'];
            if (!in_array($settings['policyVersion'] ?? null, [ConsequencePolicy::VERSION, ConsequencePolicy::PROBABILISTIC_VERSION, ConsequencePolicy::ADDRESSED_VERSION], true)) {
                throw new \InvalidArgumentException('Unsupported replay consequence policy.');
            }
            $request['consequences'] = ['policyVersion'=>$settings['policyVersion'], 'compressionPercent' => $settings['compressionPercent'], 'capturePercent' => $settings['capturePercent']];
        }
        return $request;
    }
}
