<?php

namespace Waar\MicroCombat\Workshop;

final readonly class EngineProfileMigrator
{
    /** @param array<string,mixed> $source @return array{profile:array<string,mixed>,migration:array<string,mixed>} */
    public function migrate(array $source): array
    {
        $schema=$source['schemaVersion']??null;
        if($schema===EngineProfile::SCHEMA_VERSION){
            $thresholdAdded=!array_key_exists('woundDamageThreshold',(array)($source['combat']??[]));
            if($thresholdAdded)$source['combat']['woundDamageThreshold']='0';
            $profile=EngineProfile::fromArray($source,[])->toArray();
            return ['profile'=>$profile,'migration'=>['performed'=>$thresholdAdded,'sourceSchema'=>$schema,'targetSchema'=>$schema,'modelVersion'=>EngineProfile::MODEL_VERSION,'measurementsObsolete'=>$thresholdAdded,'newFields'=>$thresholdAdded?['combat.woundDamageThreshold=0']:[],'obsoleteFields'=>[],'notes'=>$thresholdAdded?['Le seuil absent conserve le classement historique : tout survivant endommagé est blessé.']:[]]];
        }
        if($schema!==EngineProfile::LEGACY_SCHEMA_VERSION)throw new \InvalidArgumentException('Version de profil inconnue : migration impossible.');
        $profile=EngineProfile::defaults();
        $profile['combat']['woundDamageThreshold']='0';
        foreach(['id','label','relations']as$field)if(array_key_exists($field,$source))$profile[$field]=$source[$field];
        foreach(EngineProfile::UNIT_COSTS as$type=>$unused){$legacy=$source['units'][$type]??null;if($legacy===null){$profile['units'][$type]=null;continue;}if(!is_array($legacy))throw new \InvalidArgumentException('Ancienne fiche d’unité invalide : '.$type);foreach(['attack','structure','defendingEfficiency','cost','capturable']as$field)if(array_key_exists($field,$legacy))$profile['units'][$type][$field]=$legacy[$field];$profile['units'][$type]['baseAccuracy']='0.15';$profile['units'][$type]['accuracySpread']='0';$profile['units'][$type]['strikesPerAttack']=1;}
        foreach(EngineProfile::WEATHER as$condition)foreach(EngineProfile::UNIT_COSTS as$type=>$unused){$legacy=$source['weather'][$condition][$type]??null;if(is_string($legacy)||is_int($legacy))$profile['weather'][$condition][$type]['attack']=(string)$legacy;$profile['weather'][$condition][$type]['baseAccuracy']='1';}
        $combat=is_array($source['combat']??null)?$source['combat']:[];
        foreach(['maxRounds','lossCompressionPercent','capturePercent']as$field)if(array_key_exists($field,$combat))$profile['combat'][$field]=$combat[$field];
        if(in_array($combat['tieBreakPolicy']??null,['defender','draw'],true))$profile['combat']['equalityPolicy']=$combat['tieBreakPolicy'];
        $validated=EngineProfile::fromArray($profile,[])->toArray();
        return ['profile'=>$validated,'migration'=>[
            'performed'=>true,'sourceSchema'=>$schema,'targetSchema'=>EngineProfile::SCHEMA_VERSION,'modelVersion'=>EngineProfile::MODEL_VERSION,'measurementsObsolete'=>true,
            'newFields'=>['units.*.baseAccuracy=0.15','units.*.accuracySpread=0','units.*.strikesPerAttack=1','weather.*.*.baseAccuracy=1','combat.surrenderEnabled=false','combat.surrenderDeadPercent=20','combat.tieBreakCriterion=economic','combat.woundDamageThreshold=0'],
            'obsoleteFields'=>array_values(array_filter(['combat.randomSpread',isset($combat['tieBreakPolicy'])?'combat.tieBreakPolicy (migré vers equalityPolicy)':null])),
            'notes'=>['Attaque, structure, coûts, contres, coefficient défensif et capturable ont été conservés.','La météo historique reste un coefficient d’attaque ; aucune pénalité de précision n’a été déduite de son libellé.','La physique et la politique de conséquences changent : anciennes mesures, zones liées et classements sont obsolètes.'],
        ]];
    }
}
