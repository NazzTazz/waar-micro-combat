<?php

namespace App\Game\Combat;

use App\Game\Combat\Preparation\CombatPreparation;
use App\Game\Combat\Rules\CombatRuleset;

final readonly class CombatEngine
{
    public const REQUEST_SCHEMA='waar-combat-request/2';
    public function __construct(private CombatPreparation $preparation=new CombatPreparation(),private CombatResolver $resolver=new CombatResolver(new RoundResolver()),private ConsequencePolicy $consequences=new ConsequencePolicy()){}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function resolveRequest(array $request):array
    {
        self::keys($request,['schemaVersion','ruleset','attacker','defender','seed','traceLevel','consequences'],'combat request');
        if(($request['schemaVersion']??null)!==self::REQUEST_SCHEMA)throw new \InvalidArgumentException('Unsupported combat request schema.');
        if(!is_array($request['ruleset']??null)||!is_array($request['attacker']??null)||!is_array($request['defender']??null))throw new \InvalidArgumentException('Ruleset and both sides are required.');
        $ruleset=CombatRuleset::fromArray($request['ruleset']);$a=$request['attacker'];$d=$request['defender'];self::keys($a,['units','modifiers'],'attacker');self::keys($d,['units','modifiers'],'defender');
        $aPrepared=$this->preparation->prepare($ruleset,(array)($a['modifiers']??[]));$dPrepared=$this->preparation->prepare($ruleset,(array)($d['modifiers']??[]));
        $aArmy=CombatArmy::fromCounts(self::counts($a),$aPrepared);$dArmy=CombatArmy::fromCounts(self::counts($d),$dPrepared);
        $seed=$request['seed']??null;if(!is_int($seed)||$seed<0||$seed>2147483647)throw new \InvalidArgumentException('Seed must be a 31-bit non-negative integer.');
        $snapshot=new CombatSnapshot($ruleset->version,$seed,$aPrepared,$dPrepared,(string)($request['traceLevel']??'full'));
        $result=$this->resolver->resolve($aArmy,$dArmy,$ruleset,$snapshot);$out=['result'=>$result->toArray($ruleset)];
        if(isset($request['consequences'])){$settings=$request['consequences'];if(!is_array($settings))throw new \InvalidArgumentException('Consequences settings must be an object.');self::keys($settings,['compressionPercent','capturePercent'],'consequences');
            $out['consequences']=$this->consequences->project($result,$ruleset,self::percent($settings,'compressionPercent'),self::percent($settings,'capturePercent'));}
        return $out;
    }

    /** @param array<string,mixed> $side @return array<string,int> */
    private static function counts(array $side):array
    {if(!is_array($side['units']??null))throw new \InvalidArgumentException('Side units are required.');return $side['units'];}
    /** @param array<string,mixed> $data */
    private static function percent(array $data,string $key):int{$value=$data[$key]??null;if(!is_int($value))throw new \InvalidArgumentException("{$key} must be an integer.");return $value;}
    /** @param array<string,mixed> $data @param list<string> $allowed */
    private static function keys(array $data,array $allowed,string $context):void{if($unknown=array_diff(array_keys($data),$allowed))throw new \InvalidArgumentException("Unknown {$context} field: ".reset($unknown));}
}
