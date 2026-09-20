<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator;
use Waar\MicroCombat\Experiment\NormalizedEllipseBoundaryPenalty;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\Lcg31;

final class EvolutionaryProfileOptimizer
{
    public const ALGORITHM = 'waar-profile-evolution/1';
    private const TYPES = ['soldier','spearman','archer','knight'];
    private const FIELDS = ['attack','structure','baseAccuracy','defendingEfficiency'];

    private Lcg31 $random;
    /** @var list<int> */
    private array $dimensionBag=[];
    /** @var array<string,int> */
    private array $coverage=[];

    public function __construct(
        private readonly MonotypeMeasurementService $measurement=new MonotypeMeasurementService(),
        private readonly AcceptanceZoneEvaluator $evaluator=new AcceptanceZoneEvaluator(),
        private readonly NormalizedEllipseBoundaryPenalty $penalty=new NormalizedEllipseBoundaryPenalty(),
    ) {}

    /** @param array<string,mixed> $profile @param list<array<string,mixed>> $zones @param array<string,mixed> $providedBounds @return array<string,mixed> */
    public function optimize(array $profile,array $zones,string $weather='neutral',int $searchSeed=314159,int $budget=32,int $iterations=100,array $providedBounds=[],int $measurementBaseSeed=42):array
    {
        if($budget<1||$budget>256)throw new \InvalidArgumentException('Le budget doit être compris entre 1 et 256 candidats.');
        if($searchSeed<0||$searchSeed>2147483647)throw new \InvalidArgumentException('Seed de recherche invalide.');
        (new ConsequenceObjectives())->validate($profile,$zones,$weather,$measurementBaseSeed,$iterations);
        $reference=EngineProfile::fromArray($profile)->toArray();$zoneMap=[];
        foreach($zones as $zone)$zoneMap[$zone['id']]=$zone;
        $dimensions=$this->dimensions($reference,$providedBounds);
        if(!$dimensions)throw new \InvalidArgumentException('Aucun paramètre ouvert à optimiser.');
        $this->random=new Lcg31($searchSeed);$this->dimensionBag=[];$this->coverage=array_fill_keys(array_column($dimensions,'path'),0);

        $archive=[];$seen=[];$generations=[];$radius=.25;$stagnation=0;$stopReason='budget';$generation=0;
        while(count($archive)<$budget){
            $remaining=$budget-count($archive);$size=min(8,$remaining);$parents=$this->parents($archive,$dimensions);
            $generationCandidates=[];$attempts=0;
            while(count($generationCandidates)<$size&&$attempts<$size*10){
                $slot=count($generationCandidates);$attempts++;
                if($generation===0&&$slot===0){$candidateProfile=$reference;$operator='reference';$parentIds=[];$mutations=[];}
                else{
                    [$candidateProfile,$operator,$parentIds,$mutations]=$this->propose($reference,$parents,$dimensions,$generation,$slot,$radius,$stagnation);
                }
                $parsed=EngineProfile::fromArray($candidateProfile);$fingerprint=$parsed->semanticFingerprint();
                if(isset($seen[$fingerprint]))continue;
                $seen[$fingerprint]=true;$candidateProfile=$parsed->toArray();
                $measurement=$this->measurement->measure($candidateProfile,$weather,$measurementBaseSeed,$iterations);
                $scored=$this->score($measurement,$zoneMap);
                $candidate=['id'=>$fingerprint,'fingerprint'=>$fingerprint,'generation'=>$generation+1,'slot'=>count($generationCandidates)+1,'operator'=>$operator,'parentIds'=>$parentIds,'mutations'=>$mutations,'profile'=>$candidateProfile,'observations'=>$measurement,...$scored];
                $archive[]=$candidate;$generationCandidates[]=$candidate;
            }
            if(!$generationCandidates){$stopReason='no_novel_proposals';break;}
            $before=count($generations)?$generations[array_key_last($generations)]['best']['metrics']:null;
            $ranked=$this->rank($archive);$best=$ranked[0];$metrics=$this->metrics($best);
            $improved=$before===null||$this->compareMetrics($metrics,$before)<0;
            if($generation>0){if($improved){$radius=max(.02,$radius*.8);$stagnation=0;}else{$stagnation++;if($stagnation>=3){$radius=min(1,$radius*2);$stagnation=0;}}}
            $generations[]=['number'=>$generation+1,'evaluated'=>count($generationCandidates),'candidateIds'=>array_column($generationCandidates,'id'),'best'=>['candidateId'=>$best['id'],'metrics'=>$metrics],'improved'=>$improved,'radius'=>$radius];
            $generation++;
            if($best['inside']===32){$stopReason='objectives_satisfied';break;}
        }
        $ranked=$this->rank($archive);foreach($ranked as $i=>&$candidate)$candidate['rank']=$i+1;unset($candidate);
        $publicBounds=[];foreach($dimensions as$d)$publicBounds[$d['path']]=array_intersect_key($d,array_flip(['path','kind','type','target','field','current','minimum','maximum','step']));
        return ['schemaVersion'=>'waar-optimizer-report/1','algorithm'=>self::ALGORITHM,'modelVersion'=>EngineProfile::MODEL_VERSION,'referenceFingerprint'=>EngineProfile::fromArray($reference)->semanticFingerprint(),'referenceProfile'=>$reference,
            'weather'=>$weather,'searchSeed'=>$searchSeed,'measurementBaseSeed'=>$measurementBaseSeed,'candidateBudget'=>$budget,'evaluated'=>count($archive),'iterationsPerScenario'=>$iterations,'bounds'=>$publicBounds,'frozen'=>['costs'=>true,'capturable'=>true,'weather'=>true,'accuracySpread'=>true,'strikesPerAttack'=>true,'rounds'=>true,'surrender'=>true,'tieBreak'=>true,'lossCompressionPercent'=>true,'capturePercent'=>true],
            'selectionPerformed'=>false,'stopReason'=>$stopReason,'coverage'=>$this->coverage,'generations'=>$generations,'bestCandidateId'=>$ranked[0]['id']??null,'candidates'=>$ranked];
    }

    /** @return list<array{path:string,kind:string,type?:string,target?:string,field?:string,current:string,minimum:string,maximum:string,step:string,min:int,max:int,stepUnits:int}> */
    private function dimensions(array $profile,array $provided):array
    {
        $dimensions=[];
        foreach(self::TYPES as$type)foreach(self::FIELDS as$field){
            $current=(string)$profile['units'][$type][$field];$physicalMax=in_array($field,['baseAccuracy'],true)?1:(in_array($field,['defendingEfficiency'],true)?10:1000);$physicalMin=$field==='structure'?.000001:0;
            $currentUnits=FixedPoint::parse($current);$default=$field==='baseAccuracy'?['minimum'=>'0','maximum'=>'1']:($field==='defendingEfficiency'?['minimum'=>'0','maximum'=>'10']:['minimum'=>FixedPoint::format(max((int)round($physicalMin*FixedPoint::SCALE),intdiv($currentUnits,4))),'maximum'=>FixedPoint::format(min($physicalMax*FixedPoint::SCALE,$currentUnits===0?10*FixedPoint::SCALE:$currentUnits*4))]);
            $dimensions[]=$this->dimension(['path'=>"units.$type.$field",'kind'=>'unit','type'=>$type,'field'=>$field,'current'=>$current],$provided["units.$type.$field"]??$default,$physicalMin,$physicalMax);
        }
        $factors=[];foreach($profile['relations'] as$r)$factors[$r['acting']][$r['target']]=$r['factor'];
        foreach(self::TYPES as$acting)foreach(self::TYPES as$target)if($acting!==$target){$path="relations.$acting.$target.factor";$current=(string)($factors[$acting][$target]??'1');$dimensions[]=$this->dimension(['path'=>$path,'kind'=>'relation','type'=>$acting,'target'=>$target,'current'=>$current],$provided[$path]??['minimum'=>'0','maximum'=>'10'],0,10);}
        if(array_diff_key($provided,array_column($dimensions,null,'path')))throw new \InvalidArgumentException('Borne de paramètre inconnue.');
        return $dimensions;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $raw @return array<string,mixed> */
    private function dimension(array $base,array $raw,float $physicalMin,int $physicalMax):array
    {
        foreach(['minimum','maximum']as$key)if(!is_string($raw[$key]??null)&&!is_int($raw[$key]??null))throw new \InvalidArgumentException('Borne invalide : '.$base['path']);
        $step=(string)($raw['step']??'0.01');$min=FixedPoint::parse($raw['minimum']);$max=FixedPoint::parse($raw['maximum']);$stepUnits=FixedPoint::parse($step);$current=FixedPoint::parse($base['current']);
        if($stepUnits<1||$stepUnits>FixedPoint::SCALE||$min>(int)round($physicalMax*FixedPoint::SCALE)||$min>$max||$max>(int)round($physicalMax*FixedPoint::SCALE)||$min<(int)round($physicalMin*FixedPoint::SCALE)||$current<$min||$current>$max||($max-$min)%$stepUnits!==0)throw new \InvalidArgumentException('Bornes ou pas invalides : '.$base['path']);
        return [...$base,'minimum'=>FixedPoint::format($min),'maximum'=>FixedPoint::format($max),'step'=>$step,'min'=>$min,'max'=>$max,'stepUnits'=>$stepUnits];
    }

    /** @param list<array<string,mixed>> $archive @param list<array<string,mixed>> $dimensions @return list<array<string,mixed>> */
    private function parents(array $archive,array $dimensions):array
    {
        if(!$archive)return[];$ranked=$this->rank($archive);$parents=array_slice($ranked,0,min(2,count($ranked)));
        while(count($parents)<min(4,count($ranked))){$chosen=null;$distance=-1.0;foreach($ranked as$candidate){if(in_array($candidate['id'],array_column($parents,'id'),true))continue;$nearest=INF;foreach($parents as$parent)$nearest=min($nearest,$this->distance($candidate['profile'],$parent['profile'],$dimensions));if($nearest>$distance){$distance=$nearest;$chosen=$candidate;}}if($chosen===null)break;$parents[]=$chosen;}
        return$parents;
    }

    /** @return array{0:array<string,mixed>,1:string,2:list<string>,3:list<array<string,string>>} */
    private function propose(array $reference,array $parents,array $dimensions,int $generation,int $slot,float $radius,int $stagnation):array
    {
        $parent=$parents?$parents[$slot%count($parents)]:['id'=>EngineProfile::fromArray($reference)->semanticFingerprint(),'profile'=>$reference];$profile=$parent['profile'];$parentIds=[$parent['id']];
        $operator=$slot<2?'mutation':($slot<4?'multi_mutation':($slot<6?'crossover':'global'));
        if($generation===0&&$operator==='crossover')$operator='multi_mutation';
        if($operator==='crossover'&&count($parents)>1){$other=$parents[($slot+1)%count($parents)];$parentIds[]=$other['id'];foreach($dimensions as$d)if($this->random->nextIndex(2)===1)$this->set($profile,$d,$this->get($other['profile'],$d));}
        $count=$operator==='mutation'?1:2+$this->random->nextIndex(3);$global=$operator==='global'||($stagnation>0&&$slot>=4);$mutations=[];
        for($i=0;$i<min($count,count($dimensions));$i++){$index=$this->nextDimension(count($dimensions));$d=$dimensions[$index];$before=$this->get($profile,$d);$after=$this->mutated($before,$d,$radius,$global);if($after===$before)continue;$this->set($profile,$d,$after);$this->coverage[$d['path']]++;$mutations[]=['path'=>$d['path'],'before'=>$before,'after'=>$after];}
        return[$profile,$operator,$parentIds,$mutations];
    }

    private function nextDimension(int $count):int
    {
        if(!$this->dimensionBag){$this->dimensionBag=range(0,$count-1);for($i=$count-1;$i>0;$i--){$j=$this->random->nextIndex($i+1);[$this->dimensionBag[$i],$this->dimensionBag[$j]]=[$this->dimensionBag[$j],$this->dimensionBag[$i]];}}
        return array_shift($this->dimensionBag);
    }

    /** @param array<string,mixed> $d */
    private function mutated(string $before,array $d,float $radius,bool $global):string
    {
        $current=FixedPoint::parse($before);$min=$d['min'];$max=$d['max'];$step=$d['stepUnits'];$count=intdiv($max-$min,$step)+1;if($count<=1)return$before;
        if($global)$index=$this->random->nextIndex($count);
        else{$reach=max($step,(int)round(($max-$min)*$radius));$low=max($min,$current-$reach);$high=min($max,$current+$reach);$first=(int)ceil(($low-$min)/$step);$last=intdiv($high-$min,$step);$localCount=max(1,$last-$first+1);$index=$first+$this->random->nextIndex($localCount);}
        $value=$min+$index*$step;if($value===$current)$value=$value+$step<=$max?$value+$step:$value-$step;
        return FixedPoint::format($value);
    }

    /** @param array<string,mixed> $d */
    private function get(array $profile,array $d):string
    {
        if($d['kind']==='unit')return(string)$profile['units'][$d['type']][$d['field']];foreach($profile['relations']as$r)if($r['acting']===$d['type']&&$r['target']===$d['target'])return(string)$r['factor'];return'1';
    }

    /** @param array<string,mixed> $d */
    private function set(array &$profile,array $d,string $value):void
    {
        if($d['kind']==='unit'){$profile['units'][$d['type']][$d['field']]=$value;return;}
        foreach($profile['relations']as$i=>$r)if($r['acting']===$d['type']&&$r['target']===$d['target']){if($value==='1')array_splice($profile['relations'],$i,1);else$profile['relations'][$i]['factor']=$value;return;}
        if($value!=='1')$profile['relations'][]=['acting'=>$d['type'],'target'=>$d['target'],'factor'=>$value];
    }

    /** @param array<string,array<string,mixed>> $zones @return array{score:float,inside:int,total:int,worst:array{id:?string,excess:float}} */
    private function score(array $measurement,array $zones):array
    {
        $score=0.0;$inside=0;$worst=['id'=>null,'excess'=>-1.0];foreach($measurement['rows']as$row){$distance=$this->evaluator->normalizedSquaredDistance($row['winRate'],$row['rawCasualtyRatio'],$zones[$row['id']]);$excess=$this->penalty->fromSquaredDistance($distance);$score+=$excess;$inside+=(int)($excess===0.0);if($excess>$worst['excess'])$worst=['id'=>$row['id'],'excess'=>$excess];}return['score'=>$score,'inside'=>$inside,'total'=>32,'worst'=>$worst];
    }

    /** @param list<array<string,mixed>> $candidates @return list<array<string,mixed>> */
    private function rank(array $candidates):array{usort($candidates,fn($a,$b)=>$this->compareMetrics($this->metrics($a),$this->metrics($b))?:($a['fingerprint']<=>$b['fingerprint']));return$candidates;}
    /** @return array{score:float,inside:int,worst:float} */
    private function metrics(array $candidate):array{return['score'=>$candidate['score'],'inside'=>$candidate['inside'],'worst'=>$candidate['worst']['excess']];}
    private function compareMetrics(array $a,array $b):int{return[$a['score'],-$a['inside'],$a['worst']]<=>[$b['score'],-$b['inside'],$b['worst']];}
    private function distance(array $a,array $b,array $dimensions):float{$sum=0.0;foreach($dimensions as$d){$width=max(1,$d['max']-$d['min']);$sum+=abs(FixedPoint::parse($this->get($a,$d))-FixedPoint::parse($this->get($b,$d)))/$width;}return$sum/count($dimensions);}
}
