<?php

use App\Game\Army\UnitType;
use App\Game\Combat\{CombatArmy,CombatReplay,RoundResolver};
use App\Game\Combat\Preparation\CombatPreparation;
use App\Game\Combat\Rules\CombatRuleset;
use App\Game\Random\{AccuracySampler,RandomSource,SeededRandomSource};
use PHPUnit\Framework\TestCase;

/** Diagnostic wrapper only: the frozen LCG implementation is not instrumented. */
final class LegacyRandomTrace implements RandomSource
{
    private SeededRandomSource $source;
    private SeededRandomSource $counterCheck;
    public array $calls=[];
    public int $round=0;
    public string $role='';
    private int $position=0;
    public function __construct(private int $initialSeed){$this->source=new SeededRandomSource($initialSeed);$this->counterCheck=new SeededRandomSource($initialSeed);}
    public function seed(): int{return $this->initialSeed;}
    public function nextFloat(): float{throw new LogicException('Resolver should only call binomial.');}
    public function binomial(int $trials,float $probability): int
    {
        $state=new ReflectionProperty(SeededRandomSource::class,'state');
        $before=$state->getValue($this->source);$sample=$this->source->binomial($trials,$probability);
        $consumed=$trials===0||$probability<=0||$probability>=1?0:($trials<=64?$trials:2);
        for($i=0;$i<$consumed;++$i)$this->counterCheck->nextFloat();
        if($state->getValue($this->source)!==$state->getValue($this->counterCheck))throw new LogicException('Reconstructed stream position does not match actual LCG state.');
        $this->calls[]=['round'=>$this->round,'role'=>$this->role,'usage'=>debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['function'],
            'position'=>$this->position,'consumed'=>$consumed,'trials'=>$trials,'probability'=>$probability,'sample'=>$sample,'stateBefore'=>$before,'stateAfter'=>$state->getValue($this->source)];
        $this->position+=$consumed;return $sample;
    }
    public static function fixture(int $precision): array
    {
        $report=json_decode(file_get_contents(__DIR__."/fixtures/issue16-$precision.json"),true,512,JSON_THROW_ON_ERROR)['report'];
        foreach($report['result']['ruleset']['targeting'] as &$row)foreach($row['weights'] as &$weight)$weight=(float)$weight;unset($row,$weight);
        return $report;
    }
    public static function run(int $precision): array
    {
        $request=CombatReplay::request(self::fixture($precision));$rules=CombatRuleset::fromArray($request['ruleset']);
        $prepared=(new CombatPreparation())->prepare($rules,[]);
        $a=CombatArmy::fromCounts($request['attacker']['units'],$prepared);$d=CombatArmy::fromCounts($request['defender']['units'],$prepared);
        $random=new self($request['seed']);$sampler=new AccuracySampler();$resolver=new RoundResolver();
        for($round=1;$round<=2;++$round){$random->round=$round;$actions=[];
            foreach(['attacker','defender'] as $role){$random->role=$role;$values=[];
                foreach(UnitType::cases() as $t){$unit=$prepared->unit($t);$values[$t->value]=$sampler->sample($request['seed'],$round,$role,$t,$unit->baseAccuracy,$unit->accuracySpread)['value'];}
                $actions[$role]=$resolver->resolveAttacks($role==='attacker'?$a:$d,$role==='attacker'?$d:$a,$prepared,$rules,$random,$values,$role==='defender');
            }
            $a=$actions['defender']->targetArmy;$d=$actions['attacker']->targetArmy;
        }
        return $random->calls;
    }
}

final class LegacyRandomTraceTest extends TestCase
{
    public function testFirstDisplacementOccursInRoundTwoImpactAllocation(): void
    {
        $low=LegacyRandomTrace::run(25);$high=LegacyRandomTrace::run(30);
        $first=null;foreach($low as $i=>$row)if($row['consumed']!==$high[$i]['consumed']){$first=$i;break;}
        self::assertNotNull($first);
        self::assertSame(2,$low[$first]['round']);self::assertSame('attacker',$low[$first]['role']);
        self::assertSame('applyImpacts',$low[$first]['usage']);
        self::assertSame(102,$low[$first]['position']);self::assertSame(102,$high[$first]['position']);
        self::assertSame(16,$low[$first]['consumed']);self::assertSame(19,$high[$first]['consumed']);
        self::assertSame(118,$low[$first+1]['position']);self::assertSame(121,$high[$first+1]['position']);
        self::assertSame(46,$low[$first+1]['sample']);self::assertSame(53,$high[$first+1]['sample']);
    }
}
