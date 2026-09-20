<?php

namespace Waar\MicroCombat\Workshop;

final readonly class ProfileInteractionExaminer
{
    public function __construct(private MonotypeMeasurementService $measurements=new MonotypeMeasurementService(),private ProfileFeedbackService $feedback=new ProfileFeedbackService()){}

    /** @param array<string,mixed> $base @param array<string,mixed> $current @return array<string,mixed> */
    public function examine(array$base,array$current,string$interactionId,string$weather='neutral'):array
    {
        $interaction=null;foreach($this->feedback->interactions($base,$current)as$c)if($c['id']===$interactionId)$interaction=$c;
        if($interaction===null)throw new \InvalidArgumentException('Interaction absente ou plus active.');
        $variants=$this->variants($current,$interaction);$results=[];foreach($variants as$key=>$variant)$results[$key]=$this->measurements->measure($variant,$weather,42,50);
        return['interaction'=>$interaction,'variants'=>$results,'comparisons'=>$this->comparisons($results)];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $interaction @return array<string,array<string,mixed>> */
    public function variants(array$current,array$interaction):array
    {
        foreach(['pathA','pathB','beforeA','beforeB','afterA','afterB']as$key)if(!array_key_exists($key,$interaction))throw new \InvalidArgumentException('Interaction incomplète.');
        if($interaction['pathA']===$interaction['pathB']||!str_starts_with($interaction['pathA'],'units.')||!str_starts_with($interaction['pathB'],'units.'))throw new \InvalidArgumentException('Seuls deux champs numériques indépendants d’une unité peuvent être examinés.');
        $p0=$this->with($this->with($current,$interaction['pathA'],$interaction['beforeA']),$interaction['pathB'],$interaction['beforeB']);
        $pa=$this->with($p0,$interaction['pathA'],$interaction['afterA']);$pb=$this->with($p0,$interaction['pathB'],$interaction['afterB']);$pab=$this->with($this->with($p0,$interaction['pathA'],$interaction['afterA']),$interaction['pathB'],$interaction['afterB']);
        foreach([$p0,$pa,$pb,$pab]as$p)EngineProfile::fromArray($p);return['P0'=>$p0,'PA'=>$pa,'PB'=>$pb,'PAB'=>$pab];
    }
    /** @param array<string,array<string,mixed>> $results @return array<string,array<string,mixed>> */
    private function comparisons(array$results):array{$out=[];foreach($results['P0']['rows']as$row){if($row['side']!=='attacker')continue;$id=$row['scenarioId'];$rates=[];foreach($results as$key=>$measurement)foreach($measurement['rows']as$candidate)if($candidate['scenarioId']===$id&&$candidate['side']==='attacker'){$rates[$key]=$candidate['winRate'];break;}$a=$rates['PA']-$rates['P0'];$b=$rates['PB']-$rates['P0'];$message=$a*$b<0?'Les variations du taux de victoire de l’attaquant vont dans des directions opposées sur cette case.':(($a>0&&$b>0)||($a<0&&$b<0)?'Les variations du taux de victoire de l’attaquant vont dans la même direction sur cette case.':'Au moins un des deux réglages ne change pas le taux de victoire observé de l’attaquant sur cette case.');$out[$id]=['winRates'=>$rates,'deltaA'=>$a,'deltaB'=>$b,'message'=>$message];}return$out;}
    /** @param array<string,mixed> $profile @return array<string,mixed> */private function with(array$profile,string$path,mixed$value):array{$parts=explode('.',$path);$cursor=&$profile;foreach($parts as$i=>$part){if($i===count($parts)-1){$cursor[$part]=$value;break;}if(!isset($cursor[$part])||!is_array($cursor[$part]))throw new \InvalidArgumentException('Chemin de réglage invalide.');$cursor=&$cursor[$part];}return$profile;}
}
