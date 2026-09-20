<?php

namespace Waar\MicroCombat\Workshop;

final class ProfileFeedbackService
{
    private const TYPES=['soldier'=>'soldats','spearman'=>'lanciers','archer'=>'archers','knight'=>'chevaliers'];
    private const FIELDS=['attack'=>'attaque','structure'=>'structure','baseAccuracy'=>'précision','accuracySpread'=>'dispersion de précision','strikesPerAttack'=>'nombre de frappes','defendingEfficiency'=>'coefficient défensif','cost'=>'coût','capturable'=>'capture possible'];

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function analyse(array $before,array $after):array
    {
        EngineProfile::fromArray($before);EngineProfile::fromArray($after);
        $changes=$this->diff($before,$after);
        return ['changes'=>$changes,'title'=>count($changes)===1?$changes[0]['label']:'Plusieurs réglages','summary'=>$changes?implode(' ',array_slice(array_column($changes,'explanation'),0,3)):'Aucun changement de règle.'];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $current @return list<array<string,mixed>> */
    public function interactions(array $base,array $current):array
    {
        EngineProfile::fromArray($base);EngineProfile::fromArray($current);$out=[];
        foreach(array_keys(self::TYPES) as$type){$b=$base['units'][$type];$c=$current['units'][$type];
            $directions=[];foreach(['attack','baseAccuracy','structure','cost','defendingEfficiency','strikesPerAttack']as$field)$directions[$field]=$this->direction($b[$field],$c[$field]);
            if($directions['attack']*$directions['baseAccuracy']===-1)$out[]=$this->interaction($type,'attack','baseAccuracy',$b,$c,'Vous frappez '.($directions['attack']>0?'plus fort mais moins souvent.':'moins fort mais plus souvent.').' Compensation possible, sans équivalence garantie.');
            if($directions['structure']===1&&$directions['cost']===1)$out[]=$this->interaction($type,'structure','cost',$b,$c,'Chaque unité résiste davantage, mais le même budget peut en acheter moins.');
            if($directions['attack']*$directions['defendingEfficiency']===-1)$out[]=$this->interaction($type,'attack','defendingEfficiency',$b,$c,'Ces changements se compensent en partie quand le camp défend ; leur effet en attaque est différent.');
            if($directions['attack']===1&&$directions['strikesPerAttack']===1)$out[]=$this->interaction($type,'attack','strikesPerAttack',$b,$c,'Vous augmentez la puissance totale et le nombre de frappes. La puissance de chaque frappe dépend des deux réglages.');
        }
        return array_slice($out,0,2);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<array<string,mixed>> */
    public function diff(array $before,array $after):array
    {
        $changes=[];
        foreach(array_keys(self::TYPES)as$type)foreach(array_keys(self::FIELDS)as$field)if(!$this->same($before['units'][$type][$field],$after['units'][$type][$field])){$path="units.$type.$field";$changes[]=['path'=>$path,'label'=>ucfirst(self::FIELDS[$field]).' des '.self::TYPES[$type],'before'=>$before['units'][$type][$field],'after'=>$after['units'][$type][$field],'explanation'=>$this->explainUnit($type,$field,$before['units'][$type][$field],$after['units'][$type][$field])];}
        $oldRelations=$this->relations($before['relations']);$newRelations=$this->relations($after['relations']);foreach(array_unique([...array_keys($oldRelations),...array_keys($newRelations)])as$key)if(!$this->same($oldRelations[$key]??'1',$newRelations[$key]??'1')){[$acting,$target]=explode('>',$key);$from=$oldRelations[$key]??'1';$to=$newRelations[$key]??'1';$changes[]=['path'=>"relations.$acting.$target",'label'=>'Contre '.self::TYPES[$acting].' vers '.self::TYPES[$target],'before'=>$from,'after'=>$to,'explanation'=>'Le multiplicateur de dégâts des '.self::TYPES[$acting].' contre les '.self::TYPES[$target].' passe de ×'.$this->format($from).' à ×'.$this->format($to).'. Il ne change pas la préférence de ciblage.'];}
        foreach(EngineProfile::WEATHER as$weather)foreach(array_keys(self::TYPES)as$type)foreach(['attack'=>'attaque','baseAccuracy'=>'précision']as$field=>$label)if(!$this->same($before['weather'][$weather][$type][$field],$after['weather'][$weather][$type][$field])){$from=$before['weather'][$weather][$type][$field];$to=$after['weather'][$weather][$type][$field];$changes[]=['path'=>"weather.$weather.$type.$field",'label'=>ucfirst($label).' météo des '.self::TYPES[$type],'before'=>$from,'after'=>$to,'explanation'=>'Avec '.EngineProfile::WEATHER_LABELS[$weather].', le coefficient d’'.$label.' des '.self::TYPES[$type].' passe de ×'.$this->format($from).' à ×'.$this->format($to).'.'];}
        foreach(['maxRounds'=>'nombre maximal de rounds','surrenderEnabled'=>'reddition','surrenderDeadPercent'=>'seuil de reddition','tieBreakCriterion'=>'critère de départage','equalityPolicy'=>'règle d’égalité','lossCompressionPercent'=>'compression des pertes','capturePercent'=>'capture']as$field=>$label)if(!$this->same($before['combat'][$field],$after['combat'][$field])){$from=$before['combat'][$field];$to=$after['combat'][$field];$changes[]=['path'=>'combat.'.$field,'label'=>ucfirst($label),'before'=>$from,'after'=>$to,'explanation'=>$this->explainCombat($field,$from,$to,$after['combat'])];}
        return $changes;
    }

    private function explainUnit(string$type,string$field,mixed$from,mixed$to):string
    { $unit=self::TYPES[$type];$a=$this->format($from);$b=$this->format($to);return match($field){'attack'=>"L’attaque des $unit passe de $a à $b. C’est la puissance totale répartie entre leurs frappes.",'structure'=>"La structure des $unit passe de $a à $b. Elle détermine les dégâts nécessaires pour mettre une unité hors combat.",'baseAccuracy'=>"La précision des $unit passe de ".$this->percent($from).' à '.$this->percent($to).'. Elle change leur probabilité moyenne de toucher.','accuracySpread'=>"La dispersion de précision des $unit passe de ±".$this->percent($from).' à ±'.$this->percent($to).'. Une valeur nulle conserve les tirages de touches et de répartition.','strikesPerAttack'=>"Les $unit passent de $a à $b frappe".((int)$to===1?'':'s').". La puissance totale est répartie : davantage de frappes ne multiplie pas l’attaque.",'defendingEfficiency'=>"Le coefficient défensif des $unit passe de ×$a à ×$b. Il multiplie les dégâts qu’ils produisent quand leur camp défend ; il n’ajoute pas de résistance.",'cost'=>"Le coût des $unit passe de $a à $b. À budget égal, cela change leur effectif et peut aussi influer sur le départage économique.",'capturable'=>"La capture des $unit passe de ".($from?'possible':'impossible').' à '.($to?'possible':'impossible').'.',default=>ucfirst(self::FIELDS[$field])." des $unit : $a → $b."};}
    private function explainCombat(string$field,mixed$from,mixed$to,array$combat):string{return match($field){'lossCompressionPercent'=>'La compression passe de '.$from.' % à '.$to.' %. C’est une projection après combat : elle ne change pas le vainqueur brut.','capturePercent'=>'La capture passe de '.$from.' % à '.$to.' %. Les prisonniers sont pris parmi les blessés capturables du vaincu, avant compression.','surrenderEnabled'=>'La reddition est '.($to?'activée au seuil de '.$combat['surrenderDeadPercent'].' %.':'désactivée.'),'surrenderDeadPercent'=>$combat['surrenderEnabled']?'Le seuil de reddition passe de '.$from.' % à '.$to.' %.':'Le seuil conservé passe de '.$from.' % à '.$to.' %, mais la reddition reste désactivée.','maxRounds'=>'Le maximum passe de '.$from.' à '.$to.' rounds.','tieBreakCriterion'=>'Le départage passe de '.$this->format($from).' à '.$this->format($to).'.','equalityPolicy'=>'La règle d’égalité passe de '.$this->format($from).' à '.$this->format($to).'.',default=>"$field : ".$this->format($from).' → '.$this->format($to).'.'};}
    private function interaction(string$type,string$a,string$b,array$before,array$current,string$text):array{return['id'=>$type.'-'.$a.'-'.$b,'unitType'=>$type,'unitLabel'=>self::TYPES[$type],'pathA'=>"units.$type.$a",'pathB'=>"units.$type.$b",'labelA'=>self::FIELDS[$a],'labelB'=>self::FIELDS[$b],'beforeA'=>$before[$a],'afterA'=>$current[$a],'beforeB'=>$before[$b],'afterB'=>$current[$b],'message'=>$text];}
    private function direction(mixed$a,mixed$b):int{$a=(float)$a;$b=(float)$b;return$b<=>$a;}
    private function same(mixed$a,mixed$b):bool{return is_numeric($a)&&is_numeric($b)?(float)$a===(float)$b:$a===$b;}
    private function format(mixed$value):string{if(is_bool($value))return$value?'oui':'non';if(is_numeric($value))return number_format((float)$value,6,',',' ')==='0,000000'?'0':rtrim(rtrim(number_format((float)$value,6,',',' '),'0'),',');return match((string)$value){'economic'=>'coût économique','structure'=>'structure restante','draw'=>'match nul','defender'=>'avantage au défenseur',default=>(string)$value};}
    private function percent(mixed$value):string{return$this->format((float)$value*100).' %';}
    /** @param list<array<string,mixed>> $relations @return array<string,mixed> */private function relations(array$relations):array{$out=[];foreach($relations as$r)$out[$r['acting'].'>'.$r['target']]=$r['factor'];return$out;}
}
