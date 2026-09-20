<?php

namespace Waar\MicroCombat\Workshop;

use Waar\MicroCombat\FixedPoint;

final readonly class EngineProfile
{
    public const SCHEMA_VERSION = 'waar-engine-profile/0.2';
    public const LEGACY_SCHEMA_VERSION = 'waar-engine-profile/0.1';
    public const MODEL_VERSION = 'waar-cohort-v2';
    public const UNIT_COSTS = ['soldier'=>80, 'spearman'=>110, 'archer'=>130, 'knight'=>350];
    public const WEATHER = ['neutral','cloudy','snow','blizzard','heat','canicule','wind','storm','rain','thunderstorm'];
    public const WEATHER_LABELS = ['neutral'=>'Beau temps','cloudy'=>'Nuageux','snow'=>'Froid mordant','blizzard'=>'Blizzard','heat'=>'Ensoleillé','canicule'=>'Canicule','wind'=>'Vents violents','storm'=>'Tempête','rain'=>'Pluies diluviennes','thunderstorm'=>'Orages'];
    private const DEFAULT_STATS = [
        'soldier'=>['attack'=>'7','structure'=>'18','baseAccuracy'=>'0.2','accuracySpread'=>'0.01','strikesPerAttack'=>1,'defendingEfficiency'=>'1'],
        'spearman'=>['attack'=>'8','structure'=>'32','baseAccuracy'=>'0.15','accuracySpread'=>'0.01','strikesPerAttack'=>1,'defendingEfficiency'=>'1'],
        'archer'=>['attack'=>'18','structure'=>'10','baseAccuracy'=>'0.15','accuracySpread'=>'0.02','strikesPerAttack'=>1,'defendingEfficiency'=>'1'],
        'knight'=>['attack'=>'30','structure'=>'45','baseAccuracy'=>'0.15','accuracySpread'=>'0.01','strikesPerAttack'=>1,'defendingEfficiency'=>'1'],
    ];

    /** @param array<string,array<string,mixed>|null> $units @param list<array<string,mixed>> $relations @param array<string,array<string,array<string,string>>> $weather */
    private function __construct(
        public string $id,
        public string $label,
        public array $units,
        public array $relations,
        public array $weather,
        public int $maxRounds,
        public bool $surrenderEnabled,
        public int $surrenderDeadPercent,
        public string $tieBreakCriterion,
        public string $equalityPolicy,
        public int $lossCompressionPercent,
        public int $capturePercent,
    ) {}

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $units=[];
        foreach(self::UNIT_COSTS as $type=>$cost)$units[$type]=[...self::DEFAULT_STATS[$type],'cost'=>$cost,'capturable'=>false];
        $weather=[];
        foreach(self::WEATHER as $condition)foreach(self::UNIT_COSTS as $type=>$cost)$weather[$condition][$type]=['attack'=>'1','baseAccuracy'=>'1'];
        return ['schemaVersion'=>self::SCHEMA_VERSION,'id'=>'my-waar-profile','label'=>'Mon profil Waar','units'=>$units,'relations'=>[],'weather'=>$weather,
            'combat'=>['maxRounds'=>3,'surrenderEnabled'=>false,'surrenderDeadPercent'=>20,'tieBreakCriterion'=>'economic','equalityPolicy'=>'defender','lossCompressionPercent'=>8,'capturePercent'=>0]];
    }

    /** @param array<string,mixed> $values @param list<string>|null $requiredTypes */
    public static function fromArray(array $values, ?array $requiredTypes=null): self
    {
        $errors=self::validate($values,$requiredTypes);if($errors)throw new ProfileValidationException($errors);
        $relations=$values['relations']??[];usort($relations,static fn(array $a,array $b):int=>[$a['acting'],$a['target']]<=>[$b['acting'],$b['target']]);
        $units=[];$weather=[];
        foreach(self::UNIT_COSTS as $type=>$cost)$units[$type]=$values['units'][$type]??null;
        foreach(self::WEATHER as $condition)foreach(self::UNIT_COSTS as $type=>$cost)foreach(['attack','baseAccuracy'] as $field)$weather[$condition][$type][$field]=FixedPoint::format(FixedPoint::parse($values['weather'][$condition][$type][$field]));
        $combat=$values['combat'];
        return new self($values['id'],$values['label'],$units,$relations,$weather,$combat['maxRounds'],$combat['surrenderEnabled'],$combat['surrenderDeadPercent'],$combat['tieBreakCriterion'],$combat['equalityPolicy'],$combat['lossCompressionPercent'],$combat['capturePercent']);
    }

    /** @param array<string,mixed> $values @param list<string>|null $requiredTypes @return list<array{code:string,path:string,message:string}> */
    public static function validate(array $values, ?array $requiredTypes=null): array
    {
        $errors=[];$add=static function(string $code,string $path,string $message)use(&$errors):void{$errors[]=['code'=>$code,'path'=>$path,'message'=>$message];};
        foreach(array_diff(array_keys($values),['schemaVersion','id','label','units','relations','weather','combat'])as$field)$add('unknown_field',$field,'Champ de profil inconnu.');
        if(($values['schemaVersion']??null)!==self::SCHEMA_VERSION)$add('unsupported_schema','schemaVersion','Version de profil non supportée ; utilisez la migration explicite.');
        foreach(['id','label']as$field)if(!is_string($values[$field]??null)||trim($values[$field])==='')$add('required',$field,'Ce texte est requis.');
        $requiredTypes??=array_keys(self::UNIT_COSTS);
        if(!is_array($values['units']??null)||array_is_list($values['units']))$add('invalid_type','units','Les fiches d’unités doivent former un objet.');
        else{
            foreach(array_diff(array_keys($values['units']),array_keys(self::UNIT_COSTS))as$type)$add('unknown_unit','units.'.$type,'Type d’unité inconnu.');
            foreach(self::UNIT_COSTS as $type=>$cost){$unit=$values['units'][$type]??null;if($unit===null){if(in_array($type,$requiredTypes,true))$add('missing_unit','units.'.$type,'L’unité doit être configurée.');continue;}
                if(!is_array($unit)||array_is_list($unit)){$add('invalid_type','units.'.$type,'Fiche d’unité invalide.');continue;}
                foreach(array_diff(array_keys($unit),['attack','structure','baseAccuracy','accuracySpread','strikesPerAttack','defendingEfficiency','cost','capturable'])as$field)$add('unknown_field','units.'.$type.'.'.$field,'Champ d’unité inconnu.');
                foreach([['attack',0,1000],['structure',0.000001,1000],['baseAccuracy',0,1],['accuracySpread',0,1],['defendingEfficiency',0,10]]as[$field,$min,$max])self::decimalError($unit[$field]??null,'units.'.$type.'.'.$field,$min,$max,$add);
                if(!is_int($unit['strikesPerAttack']??null)||$unit['strikesPerAttack']<1||$unit['strikesPerAttack']>32)$add('out_of_range','units.'.$type.'.strikesPerAttack','Nombre entier de frappes attendu entre 1 et 32.');
                if(!is_int($unit['cost']??null)||$unit['cost']<1||$unit['cost']>400400)$add('out_of_range','units.'.$type.'.cost','Coût entier attendu entre 1 et 400 400.');
                if(!is_bool($unit['capturable']??null))$add('invalid_type','units.'.$type.'.capturable','La capture doit être oui ou non.');
            }
        }
        $seen=[];
        if(!is_array($values['relations']??null)||!array_is_list($values['relations']))$add('invalid_type','relations','Les relations doivent former une liste.');
        else foreach($values['relations']as$i=>$relation){if(!is_array($relation)){$add('invalid_type','relations.'.$i,'Relation invalide.');continue;}foreach(array_diff(array_keys($relation),['acting','target','factor'])as$field)$add('unknown_field','relations.'.$i.'.'.$field,'Champ de relation inconnu.');$a=$relation['acting']??null;$t=$relation['target']??null;$key=is_string($a)&&is_string($t)?$a.'>'.$t:'#'.$i;if(!is_string($a)||!is_string($t)||!isset(self::UNIT_COSTS[$a])||!isset(self::UNIT_COSTS[$t]))$add('unknown_unit','relations.'.$i,'Type inconnu dans la relation.');elseif($a===$t)$add('diagonal_relation','relations.'.$i,'La diagonale reste à ×1.');elseif(isset($seen[$key]))$add('duplicate_relation','relations.'.$i,'Relation dirigée dupliquée.');$seen[$key]=true;self::decimalError($relation['factor']??null,'relations.'.$i.'.factor',0,10,$add);}
        if(!is_array($values['weather']??null)||array_is_list($values['weather']))$add('invalid_type','weather','La météo doit former un objet.');
        else{foreach(array_diff(array_keys($values['weather']),self::WEATHER)as$condition)$add('unknown_field','weather.'.$condition,'Condition météo inconnue.');foreach(self::WEATHER as$condition){$row=$values['weather'][$condition]??null;if(!is_array($row)||array_is_list($row)){$add('missing_weather','weather.'.$condition,'Condition météo requise.');continue;}foreach(array_diff(array_keys($row),array_keys(self::UNIT_COSTS))as$type)$add('unknown_field','weather.'.$condition.'.'.$type,'Type d’unité inconnu pour cette météo.');foreach(self::UNIT_COSTS as$type=>$cost){$cell=$row[$type]??null;if(!is_array($cell)||array_is_list($cell)){$add('invalid_type','weather.'.$condition.'.'.$type,'Deux coefficients météo sont requis.');continue;}foreach(array_diff(array_keys($cell),['attack','baseAccuracy'])as$field)$add('unknown_field','weather.'.$condition.'.'.$type.'.'.$field,'La météo ne peut modifier que l’attaque et la précision fixe.');foreach(['attack','baseAccuracy']as$field)self::decimalError($cell[$field]??null,'weather.'.$condition.'.'.$type.'.'.$field,0,1,$add);}}}
        $combat=$values['combat']??null;
        if(!is_array($combat)||array_is_list($combat))$add('invalid_type','combat','Réglages de combat invalides.');
        else{foreach(array_diff(array_keys($combat),['maxRounds','surrenderEnabled','surrenderDeadPercent','tieBreakCriterion','equalityPolicy','lossCompressionPercent','capturePercent'])as$field)$add('unknown_field','combat.'.$field,'Réglage de combat inconnu.');foreach([['maxRounds',1,30],['surrenderDeadPercent',1,100],['lossCompressionPercent',0,100],['capturePercent',0,50]]as[$field,$min,$max])if(!is_int($combat[$field]??null)||$combat[$field]<$min||$combat[$field]>$max)$add('out_of_range','combat.'.$field,"Entier attendu entre $min et $max.");if(!is_bool($combat['surrenderEnabled']??null))$add('invalid_type','combat.surrenderEnabled','La reddition doit être activée ou désactivée.');if(!in_array($combat['tieBreakCriterion']??null,['economic','structure'],true))$add('invalid_value','combat.tieBreakCriterion','Critère attendu : coût économique ou structure.');if(!in_array($combat['equalityPolicy']??null,['draw','defender'],true))$add('invalid_value','combat.equalityPolicy','Égalité attendue : match nul ou défenseur.');}
        return $errors;
    }

    /** @param callable(string,string,string):void $add */
    private static function decimalError(mixed $value,string $path,float $min,float $max,callable $add):void
    {if(!is_string($value)&&!is_int($value)){$add('invalid_decimal',$path,'Décimal attendu sous forme de chaîne.');return;}try{$micro=FixedPoint::parse($value);$low=(int)round($min*FixedPoint::SCALE);$high=(int)round($max*FixedPoint::SCALE);if($micro<$low||$micro>$high)$add('out_of_range',$path,"Valeur attendue entre $min et $max.");}catch(\Throwable){$add('invalid_decimal',$path,'Décimal positif attendu, avec six décimales au plus.');}}

    /** @return array<string,int> */
    public function costs():array{$costs=[];foreach(self::UNIT_COSTS as$type=>$default)$costs[$type]=$this->units[$type]['cost']??$default;return $costs;}

    /** @return array<string,mixed> */
    public function ruleset():array
    {
        $units=[];$targeting=[];$engagements=[];$factors=[];foreach($this->relations as$r)$factors[$r['acting']][$r['target']]=$r['factor'];
        foreach(self::UNIT_COSTS as$type=>$defaultCost){$unit=$this->units[$type]??[...self::DEFAULT_STATS[$type],'cost'=>$defaultCost,'capturable'=>false];$units[]=['type'=>$type,'attack'=>self::decimal($unit['attack']),'structure'=>self::decimal($unit['structure']),'cost'=>$unit['cost'],'baseAccuracy'=>self::decimal($unit['baseAccuracy']),'accuracySpread'=>self::decimal($unit['accuracySpread']),'strikesPerAttack'=>$unit['strikesPerAttack'],'defendingEfficiency'=>self::decimal($unit['defendingEfficiency']),'capturable'=>$unit['capturable']];$targeting[$type]=['weights'=>array_fill_keys(array_keys(self::UNIT_COSTS),1)];foreach(self::UNIT_COSTS as$target=>$unused)$engagements[$type][$target]=['attackFactor'=>self::decimal($factors[$type][$target]??'1'),'isProvisional'=>false];}
        return ['schemaVersion'=>'waar-cohort-ruleset/2','modelVersion'=>self::MODEL_VERSION,'version'=>$this->id.'@'.substr($this->semanticFingerprint(),0,16),'units'=>$units,'targetingMode'=>'proportional','targeting'=>$targeting,'engagements'=>$engagements,'maxRounds'=>$this->maxRounds,'surrender'=>['enabled'=>$this->surrenderEnabled,'deadRatio'=>self::decimal($this->surrenderDeadPercent/100)],'tieBreak'=>['criterion'=>$this->tieBreakCriterion,'equality'=>$this->equalityPolicy]];
    }

    /** @return list<array<string,mixed>> */
    public function weatherModifiers(string $condition,string $camp):array
    {if(!in_array($condition,self::WEATHER,true))throw new \InvalidArgumentException('Condition météo inconnue.');$result=[];foreach(self::UNIT_COSTS as$type=>$unused)foreach(['attack','baseAccuracy']as$field){$value=$this->weather[$condition][$type][$field];if($value==='1')continue;$result[]=['source'=>'weather','id'=>'weather-'.$condition.'-'.$camp.'-'.$type.'-'.$field,'label'=>self::WEATHER_LABELS[$condition],'unitType'=>$type,'parameter'=>$field,'operation'=>'multiply','value'=>$value];}return$result;}

    /** @return array<string,mixed> */
    public function toArray():array{return['schemaVersion'=>self::SCHEMA_VERSION,'id'=>$this->id,'label'=>$this->label,'units'=>$this->units,'relations'=>$this->relations,'weather'=>$this->weather,'combat'=>['maxRounds'=>$this->maxRounds,'surrenderEnabled'=>$this->surrenderEnabled,'surrenderDeadPercent'=>$this->surrenderDeadPercent,'tieBreakCriterion'=>$this->tieBreakCriterion,'equalityPolicy'=>$this->equalityPolicy,'lossCompressionPercent'=>$this->lossCompressionPercent,'capturePercent'=>$this->capturePercent]];}
    public function semanticFingerprint():string{return hash('sha256',json_encode(['modelVersion'=>self::MODEL_VERSION,'profile'=>$this->toArray()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
    private static function decimal(mixed $value):string{return FixedPoint::format(FixedPoint::parse(is_float($value)?(string)$value:$value));}
}
