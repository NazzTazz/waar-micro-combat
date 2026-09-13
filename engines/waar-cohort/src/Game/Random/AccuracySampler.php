<?php

namespace App\Game\Random;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final class AccuracySampler
{
    public const VERSION='waar-accuracy-uniform-v1';
    private const MODULUS=2147483648;

    /** @return array{lower:string,upper:string,value:string,substream:string} */
    public function sample(int $seed,int $round,string $role,UnitType $type,float $base,float $spread):array
    {
        if(!in_array($role,['attacker','defender'],true)||$round<1)throw new \InvalidArgumentException('Invalid accuracy substream identity.');
        $lower=max(0,CombatFixedPoint::units($base)-CombatFixedPoint::units($spread));
        $upper=min(CombatFixedPoint::SCALE,CombatFixedPoint::units($base)+CombatFixedPoint::units($spread));
        $identity=self::VERSION."\0{$seed}\0{$round}\0{$role}\0{$type->value}";
        $state=unpack('Nvalue',substr(hash('sha256',$identity,true),0,4))['value']&0x7fffffff;
        if($lower===$upper)$value=$lower;else{
            $range=$upper-$lower+1;$bucket=intdiv(self::MODULUS,$range);$limit=$bucket*$range;
            do{$state=(int)((1103515245*$state+12345)%self::MODULUS);}while($state>=$limit);
            $value=$lower+intdiv($state,$bucket);
        }
        return ['lower'=>CombatFixedPoint::format($lower/CombatFixedPoint::SCALE),'upper'=>CombatFixedPoint::format($upper/CombatFixedPoint::SCALE),
            'value'=>CombatFixedPoint::format($value/CombatFixedPoint::SCALE),'substream'=>hash('sha256',$identity)];
    }
}
