<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\PreparedCombatSide;

final class CombatArmy
{
    /** @var array<string,array<string,UnitCohort>> */ private array $cohorts=[];
    /** @var array<string,int> */ private array $dead=[];
    /** @var array<string,int> */ private array $living=[];
    /** @var array<string,int> */ private array $initial=[];
    private int $totalLiving=0;
    private int $initialStructureUnits=0;

    /** @param iterable<UnitCohort> $cohorts @param null|array<string,int> $initial */
    public function __construct(iterable $cohorts=[], ?array $initial=null)
    {
        foreach(UnitType::cases() as $type){$this->dead[$type->value]=0;$this->living[$type->value]=0;$this->initial[$type->value]=0;}
        foreach($cohorts as $cohort)$this->add($cohort);
        foreach($this->cohorts() as $cohort)$this->initialStructureUnits+=CombatFixedPoint::units($cohort->remainingStructure)*$cohort->count;
        if(null===$initial){$this->initial=$this->living;}else foreach(UnitType::cases() as $type){
            $value=$initial[$type->value]??0;
            if(!is_int($value)||$value<$this->living[$type->value])throw new \InvalidArgumentException('Initial counts must be integers at least equal to living counts.');
            $this->initial[$type->value]=$value;$this->dead[$type->value]=$value-$this->living[$type->value];
        }
    }

    /** @param array<string,int> $counts */
    public static function fromCounts(array $counts, PreparedCombatSide $prepared):self
    {
        $known=array_map(static fn(UnitType $type)=>$type->value,UnitType::cases());if(array_diff(array_keys($counts),$known))throw new \InvalidArgumentException('Unknown unit count key.');
        $cohorts=[];$initial=[];$structureTotal=0;
        foreach(UnitType::cases() as $type){$count=$counts[$type->value]??0;if(!is_int($count)||$count<0||$count>4294967295)throw new \InvalidArgumentException('Unit counts must be non-negative 32-bit integers.');
            $unit=$prepared->unit($type);if($count>intdiv(4294967295,$unit->strikesPerAttack))throw new \InvalidArgumentException('Unit count multiplied by strikesPerAttack exceeds the native range.');$structureUnits=CombatFixedPoint::units($unit->structure);if($count>0&&$structureUnits>intdiv(PHP_INT_MAX-$structureTotal,$count))throw new \OverflowException('Initial structure exceeds the PHP fixed-point range.');$structureTotal+=$count*$structureUnits;
            $initial[$type->value]=$count;if($count>0)$cohorts[]=new UnitCohort($type,$unit->structure,$count);
        }
        return new self($cohorts,$initial);
    }

    public function __clone():void{$this->cohorts=array_map(static fn(array $rows)=>[...$rows],$this->cohorts);$this->dead=[...$this->dead];$this->living=[...$this->living];$this->initial=[...$this->initial];}
    public function add(UnitCohort $cohort):void
    {
        if(CombatFixedPoint::compare($cohort->remainingStructure,0)<=0){$this->dead[$cohort->type->value]+=$cohort->count;return;}
        $key=(string)CombatFixedPoint::units($cohort->remainingStructure);$existing=$this->cohorts[$cohort->type->value][$key]??null;
        $this->cohorts[$cohort->type->value][$key]=new UnitCohort($cohort->type,$cohort->remainingStructure,$cohort->count+($existing?->count??0));
        $this->living[$cohort->type->value]+=$cohort->count;$this->totalLiving+=$cohort->count;
    }
    /** @return list<UnitCohort> */
    public function cohorts(?UnitType $type=null):array{return null!==$type?array_values($this->cohorts[$type->value]??[]):array_values(array_merge(...array_values($this->cohorts?:[[]])));}
    /** @param iterable<UnitCohort> $cohorts */
    public function replaceType(UnitType $type,iterable $cohorts,int $additionalDeaths):void
    {
        $this->totalLiving-=$this->living[$type->value];$this->living[$type->value]=0;$this->cohorts[$type->value]=[];
        foreach($cohorts as $cohort)$this->add($cohort);$this->dead[$type->value]+=$additionalDeaths;
    }
    public function livingCount(?UnitType $type=null):int{return null===$type?$this->totalLiving:$this->living[$type->value];}
    public function deadCount(?UnitType $type=null):int{return null===$type?array_sum($this->dead):$this->dead[$type->value];}
    public function initialCount(?UnitType $type=null):int{return null===$type?array_sum($this->initial):$this->initial[$type->value];}
    /** @return array<string,int> */ public function initialCounts():array{return $this->initial;}
    public function deathRatio():float{return 0===$this->initialCount()?0.0:$this->deadCount()/$this->initialCount();}
    public function deathRatioText():string{return 0===$this->initialCount()?'0':CombatFixedPoint::formatUnits((int)round($this->deadCount()*CombatFixedPoint::SCALE/$this->initialCount(),0,PHP_ROUND_HALF_UP));}
    public function reachesDeathRatio(float $threshold):bool{return $this->initialCount()>0&&$this->deadCount()*CombatFixedPoint::SCALE>=CombatFixedPoint::units($threshold)*$this->initialCount();}
    public function remainingValue(PreparedCombatSide $prepared):int{$value=0;foreach(UnitType::cases() as $type)$value+=$this->livingCount($type)*$prepared->unit($type)->cost;return $value;}
    public function totalStructureUnits():int{$sum=0;foreach($this->cohorts() as $cohort)$sum+=CombatFixedPoint::units($cohort->remainingStructure)*$cohort->count;return $sum;}
    public function initialStructureUnits(PreparedCombatSide $prepared):int{return $this->initialStructureUnits;}
    public function woundedCount(PreparedCombatSide $prepared,?UnitType $type=null,float|int|string $woundDamageThreshold=0):int{$count=0;foreach(null===$type?UnitType::cases():[$type] as $unitType)foreach($this->cohorts($unitType) as $cohort)if(UnitState::Wounded===$cohort->state($prepared->unit($unitType)->structure,$woundDamageThreshold))$count+=$cohort->count;return $count;}

    /** @return array<string,mixed> */
    public function toArray(PreparedCombatSide $prepared,float|int|string $woundDamageThreshold=0):array
    {
        $healthy=$wounded=$dead=[];foreach(UnitType::cases() as $type){$healthy[$type->value]=0;$wounded[$type->value]=0;
            foreach($this->cohorts($type) as $cohort){if(UnitState::Valid===$cohort->state($prepared->unit($type)->structure,$woundDamageThreshold))$healthy[$type->value]+=$cohort->count;else$wounded[$type->value]+=$cohort->count;}
            $dead[$type->value]=$this->deadCount($type);
        }
        $cohorts=array_map(static fn(UnitCohort $c)=>['type'=>$c->type->value,'remainingStructure'=>CombatFixedPoint::format($c->remainingStructure),'count'=>$c->count],$this->cohorts());
        usort($cohorts,static fn(array $a,array $b)=>[$a['type'],(float)$a['remainingStructure']]<=>[$b['type'],(float)$b['remainingStructure']]);
        return ['healthy'=>$healthy,'wounded'=>$wounded,'dead'=>$dead,'cohorts'=>$cohorts];
    }
}
