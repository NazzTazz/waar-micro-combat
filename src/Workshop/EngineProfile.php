<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\CombatRuleset;
use Waar\MicroCombat\CombatTieBreakPolicy;
use Waar\MicroCombat\Experiment\UnitCatalog;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\PreparedArmy;
use Waar\MicroCombat\PreparedUnit;
use Waar\MicroCombat\UnitType;

final readonly class EngineProfile
{
    public const SCHEMA_VERSION = 'waar-engine-profile/0.1';
    public const MODEL_VERSION = 'waar-micro-combat/consequences-v1';
    public const UNIT_COSTS = ['soldier'=>80, 'spearman'=>110, 'archer'=>130, 'knight'=>350];
    public const WEATHER = ['neutral', 'rain', 'snow', 'heat'];

    /** @param array<string,array<string,mixed>|null> $units @param list<array<string,mixed>> $relations @param array<string,array<string,string>> $weather */
    private function __construct(
        public string $id,
        public string $label,
        public array $units,
        public array $relations,
        public array $weather,
        public int $maxRounds,
        public string $randomSpread,
        public string $tieBreakPolicy,
        public int $lossCompressionPercent,
        public int $capturePercent,
    ) {}

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $stats = ['soldier'=>['7','18'], 'spearman'=>['8','32'], 'archer'=>['18','10'], 'knight'=>['30','45']];
        $units = [];
        foreach (self::UNIT_COSTS as $type=>$cost) $units[$type] = ['attack'=>$stats[$type][0], 'structure'=>$stats[$type][1], 'defendingEfficiency'=>'1', 'cost'=>$cost, 'capturable'=>false];
        $weather = [];
        foreach (self::WEATHER as $condition) $weather[$condition] = array_fill_keys(array_keys(self::UNIT_COSTS), '1');
        return ['schemaVersion'=>self::SCHEMA_VERSION, 'id'=>'my-waar-profile', 'label'=>'Mon profil Waar', 'units'=>$units, 'relations'=>[], 'weather'=>$weather, 'combat'=>['maxRounds'=>3,'randomSpread'=>'0.1','tieBreakPolicy'=>'defender','lossCompressionPercent'=>8,'capturePercent'=>0]];
    }

    /** @param array<string,mixed> $values @param list<string>|null $requiredTypes */
    public static function fromArray(array $values, ?array $requiredTypes = null): self
    {
        $errors = self::validate($values, $requiredTypes);
        if ($errors) throw new ProfileValidationException($errors);
        $relations = $values['relations'] ?? [];
        usort($relations, static fn(array $a,array $b): int => [$a['acting'],$a['target']] <=> [$b['acting'],$b['target']]);
        $units=[];$weather=[];
        foreach(self::UNIT_COSTS as $type=>$cost)$units[$type]=$values['units'][$type]??null;
        foreach(self::WEATHER as $condition)foreach(self::UNIT_COSTS as $type=>$cost)$weather[$condition][$type]=FixedPoint::format(FixedPoint::parse($values['weather'][$condition][$type]));
        return new self($values['id'], $values['label'], $units, $relations, $weather, $values['combat']['maxRounds'], FixedPoint::format(FixedPoint::parse($values['combat']['randomSpread'])), $values['combat']['tieBreakPolicy'], $values['combat']['lossCompressionPercent'], $values['combat']['capturePercent']);
    }

    /** @param array<string,mixed> $values @param list<string>|null $requiredTypes @return list<array{code:string,path:string,message:string}> */
    public static function validate(array $values, ?array $requiredTypes = null): array
    {
        $errors=[]; $add=static function(string $code,string $path,string $message) use (&$errors):void{$errors[]=['code'=>$code,'path'=>$path,'message'=>$message];};
        foreach(array_diff(array_keys($values),['schemaVersion','id','label','units','relations','weather','combat']) as $field)$add('unknown_field',$field,'Champ de profil inconnu.');
        if (($values['schemaVersion']??null)!==self::SCHEMA_VERSION) $add('unsupported_schema','schemaVersion','Version de profil non supportée.');
        foreach (['id','label'] as $field) if (!is_string($values[$field]??null)||trim($values[$field])==='') $add('required',$field,'Ce texte est requis.');
        $requiredTypes ??= array_keys(self::UNIT_COSTS);
        if (!is_array($values['units']??null)||array_is_list($values['units'])) $add('invalid_type','units','Les fiches d’unités doivent former un objet.');
        else {
            foreach (array_diff(array_keys($values['units']),array_keys(self::UNIT_COSTS)) as $type) $add('unknown_unit','units.'.$type,'Type d’unité inconnu.');
            foreach (self::UNIT_COSTS as $type=>$cost) {
                $unit=$values['units'][$type]??null;
                if ($unit===null) { if(in_array($type,$requiredTypes,true)) $add('missing_unit','units.'.$type,'L’unité doit être configurée.'); continue; }
                if(!is_array($unit)||array_is_list($unit)){ $add('invalid_type','units.'.$type,'Fiche d’unité invalide.'); continue; }
                foreach(array_diff(array_keys($unit),['attack','structure','defendingEfficiency','cost','capturable']) as $field)$add('unknown_field','units.'.$type.'.'.$field,'Champ d’unité inconnu.');
                foreach ([['attack',0,1000],['structure',0.01,1000],['defendingEfficiency',0,10]] as [$field,$min,$max]) self::decimalError($unit[$field]??null,'units.'.$type.'.'.$field,$min,$max,$add);
                if (($unit['cost']??null)!==$cost) $add('fixed_cost','units.'.$type.'.cost','Le coût V1 doit rester '.$cost.'.');
                if (!is_bool($unit['capturable']??null)) $add('invalid_type','units.'.$type.'.capturable','La capture doit être oui ou non.');
            }
        }
        $seen=[];
        if(!is_array($values['relations']??null)||!array_is_list($values['relations'])) $add('invalid_type','relations','Les relations doivent former une liste.');
        else foreach($values['relations'] as $i=>$relation){
            if(!is_array($relation)){ $add('invalid_type','relations.'.$i,'Relation invalide.'); continue; }
            foreach(array_diff(array_keys($relation),['acting','target','factor']) as $field)$add('unknown_field','relations.'.$i.'.'.$field,'Champ de relation inconnu.');
            $a=$relation['acting']??null;$t=$relation['target']??null;$key=is_string($a)&&is_string($t)?$a.'>'.$t:'#'.$i;
            if(!is_string($a)||!is_string($t)||!isset(self::UNIT_COSTS[$a])||!isset(self::UNIT_COSTS[$t])) $add('unknown_unit','relations.'.$i,'Type inconnu dans la relation.');
            elseif($a===$t) $add('diagonal_relation','relations.'.$i,'La diagonale reste à ×1.');
            elseif(isset($seen[$key])) $add('duplicate_relation','relations.'.$i,'Relation dirigée dupliquée.');
            $seen[$key]=true; self::decimalError($relation['factor']??null,'relations.'.$i.'.factor',0,10,$add);
        }
        if(!is_array($values['weather']??null)||array_is_list($values['weather'])) $add('invalid_type','weather','La météo doit former un objet.');
        else {
            foreach(array_diff(array_keys($values['weather']),self::WEATHER) as $condition)$add('unknown_field','weather.'.$condition,'Condition météo inconnue.');
            foreach(self::WEATHER as $condition){
                $row=$values['weather'][$condition]??null;
                if(!is_array($row)||array_is_list($row)){ $add('missing_weather','weather.'.$condition,'Condition météo requise.'); continue; }
                foreach(array_diff(array_keys($row),array_keys(self::UNIT_COSTS)) as $type)$add('unknown_field','weather.'.$condition.'.'.$type,'Type d’unité inconnu pour cette météo.');
                foreach(self::UNIT_COSTS as $type=>$cost) self::decimalError($row[$type]??null,'weather.'.$condition.'.'.$type,0,2,$add);
            }
        }
        $combat=$values['combat']??null;
        if(!is_array($combat)||array_is_list($combat)) $add('invalid_type','combat','Réglages de combat invalides.');
        else {
            foreach(array_diff(array_keys($combat),['maxRounds','randomSpread','tieBreakPolicy','lossCompressionPercent','capturePercent']) as $field)$add('unknown_field','combat.'.$field,'Réglage de combat inconnu.');
            foreach([['maxRounds',1,30],['lossCompressionPercent',0,100],['capturePercent',0,10]] as [$field,$min,$max]) if(!is_int($combat[$field]??null)||$combat[$field]<$min||$combat[$field]>$max)$add('out_of_range','combat.'.$field,"Entier attendu entre $min et $max.");
            self::decimalError($combat['randomSpread']??null,'combat.randomSpread',0,.5,$add);
            if(!in_array($combat['tieBreakPolicy']??null,['draw','defender'],true))$add('invalid_value','combat.tieBreakPolicy','Départage attendu : draw ou defender.');
        }
        return $errors;
    }

    /** @param callable(string,string,string):void $add */
    private static function decimalError(mixed $value,string $path,float $min,float $max,callable $add):void
    {
        if(!is_string($value)&&!is_int($value)){ $add('invalid_decimal',$path,'Décimal attendu sous forme de chaîne.'); return; }
        try{$micro=FixedPoint::parse($value);$low=(int)round($min*FixedPoint::SCALE);$high=(int)round($max*FixedPoint::SCALE);if($micro<$low||$micro>$high)$add('out_of_range',$path,"Valeur attendue entre $min et $max.");}catch(\Throwable){$add('invalid_decimal',$path,'Décimal positif attendu, avec six décimales au plus.');}
    }

    public function ruleset(): CombatRuleset
    {
        $ruleset=CombatRuleset::neutral($this->id,$this->semanticFingerprint(),$this->maxRounds,$this->randomSpread,CombatTieBreakPolicy::from($this->tieBreakPolicy));
        foreach($this->relations as $r)$ruleset=$ruleset->withDamageFactor(UnitType::from($r['acting']),UnitType::from($r['target']),$r['factor']);
        return $ruleset;
    }

    /** @param array<string,int> $counts */
    public function prepareArmy(array $counts,string $condition): PreparedArmy
    {
        if(!in_array($condition,self::WEATHER,true))throw new \InvalidArgumentException('Condition météo inconnue.');
        $prepared=[];
        foreach(UnitType::cases() as $type){$v=$this->units[$type->value];if($v===null)$v=['attack'=>'0','structure'=>'1','defendingEfficiency'=>'1','cost'=>self::UNIT_COSTS[$type->value],'capturable'=>false];$attack=FixedPoint::mulDivNearest(FixedPoint::parse($v['attack']),FixedPoint::parse($this->weather[$condition][$type->value]),FixedPoint::SCALE);$prepared[]=new PreparedUnit($type,$counts[$type->value]??0,$attack,FixedPoint::parse($v['structure']),FixedPoint::parse($v['defendingEfficiency']),$v['cost']);}
        return new PreparedArmy($prepared);
    }

    /** @return array<string,mixed> */
    public function toArray(): array{return ['schemaVersion'=>self::SCHEMA_VERSION,'id'=>$this->id,'label'=>$this->label,'units'=>$this->units,'relations'=>$this->relations,'weather'=>$this->weather,'combat'=>['maxRounds'=>$this->maxRounds,'randomSpread'=>$this->randomSpread,'tieBreakPolicy'=>$this->tieBreakPolicy,'lossCompressionPercent'=>$this->lossCompressionPercent,'capturePercent'=>$this->capturePercent]];}

    public function semanticFingerprint(): string{return hash('sha256',json_encode(['modelVersion'=>self::MODEL_VERSION,'profile'=>$this->toArray()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
}
