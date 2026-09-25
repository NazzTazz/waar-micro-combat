<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator;
use Waar\MicroCombat\Experiment\NormalizedEllipseBoundaryPenalty;
use Waar\MicroCombat\FixedPoint;

final readonly class BoundedProfileSearch
{
    public function __construct(private MonotypeMeasurementService $measurement=new MonotypeMeasurementService(),private AcceptanceZoneEvaluator $evaluator=new AcceptanceZoneEvaluator(),private NormalizedEllipseBoundaryPenalty $penalty=new NormalizedEllipseBoundaryPenalty()){}

    /** @param array<string,mixed> $profile @param list<array<string,mixed>> $zones @return array<string,mixed> */
    public function search(array $profile,array $zones,string $weather='neutral',int $baseSeed=314159,int $budget=8,int $iterations=100,array $bounds=[],int $measurementBaseSeed=42):array
    {
        if($budget<1||$budget>8)throw new \InvalidArgumentException('Le budget V1 est compris entre 1 et 8 candidats.');
        if($baseSeed<0||$baseSeed>2147483647)throw new \InvalidArgumentException('Seed de recherche invalide.');
        (new ConsequenceObjectives())->validate($profile,$zones,$weather,$measurementBaseSeed,$iterations);
        $reference=EngineProfile::fromArray($profile);$zoneMap=[];
        foreach($zones as $zone){if(!is_array($zone)||!is_string($zone['id']??null)||isset($zoneMap[$zone['id']]))throw new \InvalidArgumentException('Objectifs incomplets ou dupliqués.');$this->evaluator->normalizedSquaredDistance((float)$zone['center']['x'],(float)$zone['center']['y'],$zone);$zoneMap[$zone['id']]=$zone;}
        if(count($zoneMap)!==32)throw new \InvalidArgumentException('Les 32 zones confirmées sont requises.');
        $paths=[];foreach($reference->units as $type=>$unit)foreach(['attack','structure','defendingEfficiency'] as $field)$paths[]=['id'=>'units.'.$type.'.'.$field,'kind'=>'unit','type'=>$type,'field'=>$field,'current'=>$unit[$field]];foreach($reference->relations as $i=>$relation)$paths[]=['id'=>'relations.'.$relation['acting'].'.'.$relation['target'].'.factor','kind'=>'relation','index'=>$i,'field'=>'factor','current'=>$relation['factor']];
        $bounds=$this->bounds($paths,$bounds);
        $candidates=[];$seen=[];
        for($i=0;$i<$budget*10&&count($candidates)<$budget;++$i){$candidate=$reference->toArray();if($i>0&&$paths){$path=$paths[(int)floor(($i-1)*count($paths)/max(1,$budget-1))%count($paths)];$range=$bounds[$path['id']];$next=$i%2?$range['maximum']:$range['minimum'];if($next===$path['current'])$next=$next===$range['minimum']?$range['maximum']:$range['minimum'];if($path['kind']==='unit')$candidate['units'][$path['type']][$path['field']]=$next;else$candidate['relations'][$path['index']]['factor']=$next;}
            $parsed=EngineProfile::fromArray($candidate);$fingerprint=$parsed->semanticFingerprint();if(isset($seen[$fingerprint]))continue;$seen[$fingerprint]=true;$measure=$this->measurement->measure($candidate,$weather,$measurementBaseSeed,$iterations);$loss=0;$inside=0;$worst=['id'=>null,'excess'=>-1.0];foreach($measure['rows'] as $row){$zone=$zoneMap[$row['id']]??throw new \InvalidArgumentException('Zone manquante : '.$row['id']);$distance=$this->evaluator->normalizedSquaredDistance($row['winRate'],$row['rawCasualtyRatio'],$zone);$excess=$this->penalty->fromSquaredDistance($distance);$loss+=$excess;$inside+=(int)($excess===0.0);if($excess>$worst['excess'])$worst=['id'=>$row['id'],'excess'=>$excess];}$candidates[]=['rank'=>0,'fingerprint'=>$fingerprint,'profile'=>$candidate,'score'=>$loss,'inside'=>$inside,'total'=>32,'worst'=>$worst,'observations'=>$measure];
        }
        usort($candidates,static fn(array $a,array $b):int=>[$a['score'],$a['fingerprint']]<=>[$b['score'],$b['fingerprint']]);foreach($candidates as $i=>&$candidate)$candidate['rank']=$i+1;unset($candidate);
        return ['schemaVersion'=>'waar-bounded-profile-search/0.2','modelVersion'=>EngineProfile::MODEL_VERSION,'referenceFingerprint'=>$reference->semanticFingerprint(),'referenceProfile'=>$reference->toArray(),'weather'=>$weather,'searchSeed'=>$baseSeed,'measurementBaseSeed'=>$measurementBaseSeed,'candidateBudget'=>$budget,'evaluated'=>count($candidates),'proposalLimit'=>80,'iterationsPerScenario'=>$iterations,'bounds'=>$bounds,'zones'=>$zones,'frozen'=>['costs'=>true,'capturable'=>true,'weather'=>true,'baseAccuracy'=>true,'accuracySpread'=>true,'strikesPerAttack'=>true,'rounds'=>true,'surrender'=>true,'tieBreak'=>true,'lossCompressionPercent'=>true,'capturePercent'=>true,'woundDamageThreshold'=>true],'selectionPerformed'=>false,'candidates'=>$candidates];
    }

    /** @param list<array<string,mixed>> $paths @param array<string,mixed> $provided @return array<string,array{minimum:string,maximum:string}> */
    private function bounds(array $paths,array $provided):array
    {
        $result=[];
        foreach($paths as $path){$current=FixedPoint::parse($path['current']);$min=max($path['field']==='structure'?10000:0,$current-intdiv($current,5));$maxLimit=in_array($path['field'],['defendingEfficiency','factor'],true)?10*FixedPoint::SCALE:1000*FixedPoint::SCALE;$max=min($maxLimit,$current+intdiv($current,5));$raw=$provided[$path['id']]??['minimum'=>FixedPoint::format($min),'maximum'=>FixedPoint::format($max)];foreach(['minimum','maximum'] as $key)if(!is_string($raw[$key]??null)&&!is_int($raw[$key]??null))throw new \InvalidArgumentException('Borne invalide : '.$path['id']);$min=FixedPoint::parse($raw['minimum']);$max=FixedPoint::parse($raw['maximum']);if($min>$current||$current>$max||$max>$maxLimit||($path['field']==='structure'&&$min===0)||$min%10000!==0||$max%10000!==0)throw new \InvalidArgumentException('Bornes ou pas 0,01 invalides : '.$path['id']);$result[$path['id']]=['minimum'=>FixedPoint::format($min),'maximum'=>FixedPoint::format($max)];}
        if(array_diff_key($provided,$result))throw new \InvalidArgumentException('Borne de paramètre inconnue.');
        return $result;
    }
}
