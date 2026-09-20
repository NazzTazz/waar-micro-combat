<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator;
use Waar\MicroCombat\UnitType;

/** Validation only: importing objectives never measures or approves them. */
final class ConsequenceObjectives
{
    public const SCHEMA = 'waar-consequence-acceptance-zones/0.1';

    public function validate(array $profileValues, array $zones, string $weather, int $seed, int $iterations, ?array $runtime=null): array
    {
        $profile = EngineProfile::fromArray($profileValues);
        if (!in_array($weather, EngineProfile::WEATHER, true) || $seed < 0 || $seed > 2147483647 || $iterations < 1 || $iterations > 100) {
            throw new \InvalidArgumentException('Contexte de mesure invalide.');
        }
        $context = ['weather'=>$weather, 'baseSeed'=>$seed, 'iterations'=>$iterations, 'budget'=>MonotypeMeasurementService::BUDGET, 'objectiveMetric'=>'rawCasualtyRatio',
            'modelVersion'=>EngineProfile::MODEL_VERSION,'rulesetVersion'=>$profile->ruleset()['version'],
            'consequences'=>['lossCompressionPercent'=>$profile->lossCompressionPercent, 'capturePercent'=>$profile->capturePercent]];
        $expected = [];
        foreach (UnitType::cases() as $a) foreach (UnitType::cases() as $b) foreach (['attacker','defender'] as $side) {
            $expected[$a->value.'-vs-'.$b->value.'/'.$side] = true;
        }
        if (!array_is_list($zones) || count($zones) !== 32) throw new \InvalidArgumentException('Les 32 zones sont requises.');
        $seen = [];
        $evaluator = new AcceptanceZoneEvaluator();
        foreach ($zones as $zone) {
            if (!is_array($zone)) throw new \InvalidArgumentException('Objectif invalide.');
            if (($zone['enabled']??true)!==true) throw new \InvalidArgumentException('Réactivez les 32 objectifs avant la recherche.');
            $id = $zone['id'] ?? null;
            if (!is_string($id) || !isset($expected[$id]) || isset($seen[$id])) throw new \InvalidArgumentException('Objectif inconnu ou dupliqué.');
            $seen[$id] = true;
            $zoneContext=$zone['context']??null;$actual=is_array($zoneContext)?array_diff_key($zoneContext,['runtime'=>true]):null;
            $runtimeMatches=is_array($zoneContext)&&is_array($zoneContext['runtime']??null)&&($zoneContext['runtime']['modelVersion']??null)===EngineProfile::MODEL_VERSION
                &&($runtime===null||self::canonical($zoneContext['runtime'])===self::canonical($runtime));
            if (($zone['sourceFingerprint'] ?? null) !== $profile->semanticFingerprint() || ($zone['modelVersion'] ?? null) !== EngineProfile::MODEL_VERSION
                || !is_array($actual) || self::canonical($actual) !== self::canonical($context) || !$runtimeMatches) {
                throw new \InvalidArgumentException('Les objectifs ne correspondent pas au profil et au contexte de mesure courants.');
            }
            $evaluator->normalizedSquaredDistance(0.0, 0.0, $zone);
        }
        return $zones;
    }

    private static function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as &$entry) if (is_array($entry)) $entry = self::canonical($entry);
        return $value;
    }
}
