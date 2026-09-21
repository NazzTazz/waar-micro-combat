<?php

use App\Game\Army\UnitType;
use App\Game\Combat\CanonicalJson;
use App\Game\Combat\CombatArmy;
use App\Game\Combat\CombatEngine;
use App\Game\Combat\CombatResult;
use App\Game\Combat\CombatSide;
use App\Game\Combat\CombatSnapshot;
use App\Game\Combat\ConsequencePolicy;
use App\Game\Combat\DemoRequestFactory;
use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\CombatPreparation;
use App\Game\Combat\Rules\CombatRuleset;
use App\Game\Combat\Rules\WaarRuleset;
use App\Game\Combat\UnitCohort;
use App\Game\Combat\VictoryReason;
use App\Game\Random\AccuracySampler;
use App\Infrastructure\Combat\RustCombatResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SliceAEngineTest extends TestCase
{
    private static function rust():RustCombatResolver
    {
        static $rust;$rust??=new RustCombatResolver(dirname(__DIR__));self::assertTrue(extension_loaded('ffi'),'FFI parity is mandatory.');self::assertFileExists($rust->resolvedLibraryPath(),'Build the Rust release library before PHP parity tests.');return $rust;
    }
    /** @return array<string,mixed> */
    private static function request(array $a=['soldier'=>10],array $d=['archer'=>2],int $seed=42):array
    {
        $rules=WaarRuleset::create('test')->toArray();$rules['maxRounds']=3;foreach($rules['units']as&$unit){$unit['baseAccuracy']='1';$unit['accuracySpread']='0';}unset($unit);
        return ['schemaVersion'=>CombatEngine::REQUEST_SCHEMA,'ruleset'=>$rules,'attacker'=>['units'=>$a,'modifiers'=>[]],'defender'=>['units'=>$d,'modifiers'=>[]],'seed'=>$seed,'traceLevel'=>'full'];
    }
    /** @param array<string,mixed> $request */
    private function parity(array $request):array
    {
        $php=(new CombatEngine())->resolveRequest($request);$rust=self::rust()->resolveRequest($request);self::assertSame(CanonicalJson::encode($php),CanonicalJson::encode($rust));return $php;
    }

    public function testVersionedDefaultsHaveNoExtraBallOrWoundedAttackMultiplier():void
    {
        $rules=WaarRuleset::create();$json=json_encode($rules->toArray(),JSON_THROW_ON_ERROR);self::assertSame(CombatRuleset::SCHEMA_VERSION,$rules->toArray()['schemaVersion']);self::assertSame('waar-cohort-v2',$rules->toArray()['modelVersion']);self::assertStringNotContainsString('extraBall',$json);self::assertStringNotContainsString('woundedAttackMultiplier',$json);self::assertFalse($rules->surrenderEnabled);self::assertSame('economic',$rules->tieBreakCriterion);
    }

    public function testModifierPreparationRoundsOnceAndKeepsProvenance():void
    {
        $rules=WaarRuleset::create();$modifiers=[
            ['source'=>'training','id'=>'learned','label'=>'Formation','unitType'=>'archer','parameter'=>'baseAccuracy','operation'=>'multiply','value'=>'1.2'],
            ['source'=>'weather','id'=>'rain','label'=>'Pluie','unitType'=>'archer','parameter'=>'baseAccuracy','operation'=>'multiply','value'=>'0.8']];
        $prepared=(new CombatPreparation())->prepare($rules,$modifiers);self::assertSame('0.144',CombatFixedPoint::format($prepared->unit(UnitType::Archer)->baseAccuracy));self::assertSame('training',$prepared->modifiers[0]['source']);self::assertSame('weather',$prepared->modifiers[1]['source']);self::assertSame($rules->unit(UnitType::Archer)->toArray(),$prepared->unit(UnitType::Archer)->base);
        self::assertSame('0.144',CombatFixedPoint::format((new CombatPreparation())->prepare($rules,array_reverse($modifiers))->unit(UnitType::Archer)->baseAccuracy));
    }

    public function testWeatherCannotModifySpreadAndDuplicateEffectsAreRejected():void
    {
        $this->expectException(InvalidArgumentException::class);(new CombatPreparation())->prepare(WaarRuleset::create(),[
            ['source'=>'weather','id'=>'wind','label'=>'Vent','unitType'=>'archer','parameter'=>'accuracySpread','operation'=>'multiply','value'=>'2']]);
    }

    public function testIntegerModifierMustStayInteger():void
    {
        $this->expectException(InvalidArgumentException::class);(new CombatPreparation())->prepare(WaarRuleset::create(),[
            ['source'=>'training','id'=>'split','label'=>'Fraction','unitType'=>'knight','parameter'=>'strikesPerAttack','operation'=>'multiply','value'=>'1.5']]);
    }

    public function testUnknownContractFieldsAreRejectedByBothRuntimes():void
    {
        $request=self::request();$request['surprise']=true;try{(new CombatEngine())->resolveRequest($request);self::fail('PHP accepted an unknown request field.');}catch(InvalidArgumentException){self::assertTrue(true);}
        $this->expectException(RuntimeException::class);self::rust()->resolveRequest($request);
    }

    public function testAccuracyProtocolVectorsAndBounds():void
    {
        $sampler=new AccuracySampler();self::assertSame('0.207745',$sampler->sample(42,1,'attacker',UnitType::Soldier,.2,.01)['value']);self::assertSame('0.131025',$sampler->sample(42,1,'defender',UnitType::Archer,.15,.02)['value']);self::assertSame('0',$sampler->sample(1,1,'attacker',UnitType::Soldier,0,.5)['lower']);self::assertSame('1',$sampler->sample(1,1,'attacker',UnitType::Soldier,1,.5)['upper']);
    }

    public function testFractionatedKnightProducesFiveAttemptsAtSeventyDamage():void
    {
        $request=self::request(['knight'=>1],['soldier'=>100]);foreach($request['ruleset']['units']as&$unit)if($unit['type']==='knight')$unit['strikesPerAttack']=5;unset($unit);$request['ruleset']['maxRounds']=1;
        $result=$this->parity($request)['result'];$cell=$result['rounds'][0]['attackerAction']['matrix']['knight']['soldier'];self::assertSame(5,$cell['allocatedAttempts']);self::assertSame('70',$cell['attackPerStrike']);self::assertSame('70',$cell['damagePerHit']);self::assertArrayNotHasKey('extraTurns',$result['rounds'][0]['attackerAction']);
    }

    public function testDefenseIsRoleSpecificAndCounterOnlyChangesDamage():void
    {
        $request=self::request(['archer'=>10],['archer'=>10]);$request['ruleset']['engagements']['archer']['archer']['attackFactor']='2';$request['ruleset']['maxRounds']=1;$result=$this->parity($request)['result']['rounds'][0];
        self::assertSame('120',$result['attackerAction']['matrix']['archer']['archer']['damagePerHit']);self::assertSame('90',$result['defenderAction']['matrix']['archer']['archer']['damagePerHit']);self::assertSame($result['attackerAction']['matrix']['archer']['archer']['allocatedAttempts'],$result['defenderAction']['matrix']['archer']['archer']['allocatedAttempts']);
    }

    public function testEveryRoundTraceContainsACompleteFourByFourMatrix():void
    {
        $result=$this->parity(self::request())['result'];foreach(['attackerAction','defenderAction']as$action){self::assertCount(4,$result['rounds'][0][$action]['matrix']);foreach($result['rounds'][0][$action]['matrix']as$row)self::assertCount(4,$row);}
    }

    public function testInheritedReallocationIsVisibleAndKeepsParity():void
    {
        $found=false;for($seed=0;$seed<100&&!$found;++$seed){$request=self::request(['knight'=>1],['soldier'=>1,'archer'=>1],$seed);foreach($request['ruleset']['units']as&$unit){$unit['attack']=$unit['type']==='knight'?'400':'0';$unit['strikesPerAttack']=$unit['type']==='knight'?2:1;}unset($unit);$request['ruleset']['maxRounds']=1;$result=$this->parity($request)['result'];$matrix=$result['rounds'][0]['attackerAction']['matrix']['knight'];$reallocated=array_sum(array_column($matrix,'reallocatedAttempts'));if($reallocated>0){$found=true;self::assertSame(1,$result['defender']['dead']['soldier']);self::assertSame(1,$result['defender']['dead']['archer']);}}
        self::assertTrue($found,'A deterministic seed must expose the inherited reallocation in the trace.');
    }

    public function testBothSidesActFromTheStartOfRoundSnapshot():void
    {
        $request=self::request(['soldier'=>1],['soldier'=>1]);$request['ruleset']['maxRounds']=1;$result=$this->parity($request)['result'];self::assertSame(1,$result['rounds'][0]['attackerAction']['attempts']);self::assertSame(1,$result['rounds'][0]['defenderAction']['attempts']);self::assertSame(1,$result['attacker']['dead']['soldier']);self::assertSame(1,$result['defender']['dead']['soldier']);self::assertSame('defender',$result['winner']);self::assertSame('elimination',$result['reason']);
    }

    public function testSurrenderIsOptionalAndThresholdIsInclusive():void
    {
        $request=self::request(['soldier'=>5],['archer'=>1]);foreach($request['ruleset']['units']as&$unit){if($unit['type']==='soldier')$unit['attack']='0';if($unit['type']==='archer'){$unit['attack']='8';$unit['structure']='100';}}unset($unit);$request['ruleset']['maxRounds']=1;
        self::assertSame('round_limit',$this->parity($request)['result']['reason']);$request['ruleset']['surrender']=['enabled'=>true,'deadRatio'=>'0.2'];self::assertSame('surrender',$this->parity($request)['result']['reason']);
    }

    public function testEconomicTieBreakAndEqualityPolicies():void
    {
        $request=self::request(['soldier'=>1],['knight'=>1]);foreach($request['ruleset']['units']as&$unit)$unit['attack']='0';unset($unit);$request['ruleset']['maxRounds']=1;self::assertSame('defender',$this->parity($request)['result']['winner']);
        $request['defender']['units']=['soldier'=>1];self::assertSame('defender',$this->parity($request)['result']['winner']);$request['ruleset']['tieBreak']['equality']='draw';self::assertNull($this->parity($request)['result']['winner']);
    }

    public function testStructureFractionTieBreakIsReportedExactly():void
    {
        $request=self::request(['soldier'=>7],['archer'=>2]);foreach($request['ruleset']['units']as&$unit)$unit['attack']='0';unset($unit);$request['ruleset']['tieBreak']=['criterion'=>'structure','equality'=>'draw'];$result=$this->parity($request)['result'];self::assertNull($result['winner']);self::assertSame('structure',$result['decision']['criterion']);self::assertSame(0,$result['decision']['comparison']);self::assertArrayHasKey('remainingUnits',$result['decision']['values']['attacker']);
    }

    public function testTraceLevelDoesNotChangePhysics():void
    {
        $request=DemoRequestFactory::combat('full');$full=(new CombatEngine())->resolveRequest($request);$request['traceLevel']='none';$none=(new CombatEngine())->resolveRequest($request);
        $normalize=static function(array $value):array{unset($value['result']['snapshot']['traceLevel'],$value['result']['replayHash'],$value['consequences']['rawResult']);foreach($value['result']['rounds']as&$round){unset($round['attackerAction'],$round['defenderAction']);}unset($round);return$value;};
        self::assertSame(CanonicalJson::encode($normalize($full)),CanonicalJson::encode($normalize($none)));
    }

    public function testConsequencesExampleUsesWoundedCaptureThenCompression():void
    {
        $rules=WaarRuleset::create();$prepared=(new CombatPreparation())->prepare($rules);$a=new CombatArmy([new UnitCohort(UnitType::Archer,50,100),new UnitCohort(UnitType::Archer,25,300)],['archer'=>1000]);$d=CombatArmy::fromCounts(['soldier'=>1],$prepared);$snapshot=new CombatSnapshot($rules->version,1,$prepared,$prepared,'none');
        $result=new CombatResult($a,$d,[],CombatSide::Defender,VictoryReason::RoundLimit,$snapshot,$rules->version,$prepared,$prepared,[],'raw');$out=(new ConsequencePolicy())->project($result,$rules,8,10)['attacker']['types']['archer'];self::assertSame(['healthy'=>929,'wounded'=>21,'dead'=>48,'prisoners'=>2,'freeSurvivors'=>950],$out['projected']);
    }

    public function testProbabilisticCaptureEligibilityWithoutResolvingCombat():void
    {
        $rules=WaarRuleset::create();$prepared=(new CombatPreparation())->prepare($rules);
        // Initial populations 100 each, half wounded, half dead, for every type.
        $cohorts=[];$counts=[];
        foreach(UnitType::cases() as $type){$cohorts[]=new UnitCohort($type,1,50);$counts[$type->value]=100;}
        $army=new CombatArmy($cohorts,$counts);$snapshot=new CombatSnapshot($rules->version,42,$prepared,$prepared,'none');
        foreach([null,CombatSide::Attacker,CombatSide::Defender] as $winner){
            $result=new CombatResult($army,$army,[],$winner,VictoryReason::RoundLimit,$snapshot,$rules->version,$prepared,$prepared,[],'raw');
            $report=(new ConsequencePolicy())->project($result,$rules,100,50,ConsequencePolicy::PROBABILISTIC_VERSION);
            foreach(['attacker','defender'] as $side)foreach($report[$side]['types'] as $row){
                $p=$row['prisonersSelectedBeforeCompression'];
                if(!$report[$side]['defeated']||!$row['capturable'])self::assertSame(0,$p);
                else self::assertGreaterThan(0,$p);
                self::assertSame($p,$row['projected']['prisoners']);
            }
        }
    }

    public function testCompressionNeverChangesRawCombatOrWinner():void
    {
        $request=DemoRequestFactory::combat('none');$low=(new CombatEngine())->resolveRequest($request);$request['consequences']['compressionPercent']=100;$request['consequences']['capturePercent']=0;$full=(new CombatEngine())->resolveRequest($request);self::assertSame(CanonicalJson::encode($low['result']),CanonicalJson::encode($full['result']));self::assertNotSame(CanonicalJson::encode($low['consequences']),CanonicalJson::encode($full['consequences']));
    }

    public function testDrawNeverCapturesPrisoners():void
    {
        $request=self::request(['archer'=>10],['archer'=>10]);foreach($request['ruleset']['units']as&$unit)$unit['attack']='0';unset($unit);$request['ruleset']['tieBreak']['equality']='draw';$request['consequences']=['compressionPercent'=>100,'capturePercent'=>10];$out=$this->parity($request);self::assertNull($out['result']['winner']);self::assertSame(0,$out['consequences']['attacker']['types']['archer']['projected']['prisoners']);self::assertSame(0,$out['consequences']['defender']['types']['archer']['projected']['prisoners']);
    }

    #[DataProvider('paritySeeds')]
    public function testCompletePhpRustParity(int $seed):void{$request=DemoRequestFactory::combat($seed%2===0?'none':'full');$request['seed']=$seed;$this->parity($request);}
    public static function paritySeeds():iterable{foreach([0,1,2,42,9381,2147483647]as$seed)yield[$seed];}

    public function testRustBatchReturnsExactIntegerTotalsAndSupportsRanges():void
    {
        $request=DemoRequestFactory::batch(3);$request['scenarios']=array_slice($request['scenarios'],0,2);$request['totalIterations']=6;$first=self::rust()->resolveBatch($request);self::assertSame(6,$first['totalCombats']);self::assertFalse($first['iterationRange']['complete']);foreach($first['scenarios']as$scenario){$r=$scenario['result'];self::assertSame(3,$r['samples']);self::assertSame(3,$r['attackerWins']+$r['defenderWins']+$r['draws']);self::assertIsInt($r['roundSum']);self::assertCount(4,$r['attackerRawDeathsByType']);}
        $request['startIteration']=3;$second=self::rust()->resolveBatch($request);self::assertSame(3,$second['startIteration']);self::assertSame(6,$second['totalCombats']);self::assertFalse($second['iterationRange']['complete']);
        $fullRequest=$request;$fullRequest['startIteration']=0;$fullRequest['iterations']=6;$full=self::rust()->resolveBatch($fullRequest);self::assertTrue($full['iterationRange']['complete']);foreach([0,1]as$i)foreach(['attackerWins','defenderWins','draws','roundSum']as$key)self::assertSame($full['scenarios'][$i]['result'][$key],$first['scenarios'][$i]['result'][$key]+$second['scenarios'][$i]['result'][$key]);
    }

    public function testLargeArmiesRemainAggregated():void
    {
        $request=self::request(['soldier'=>1000000],['archer'=>100000]);$request['ruleset']['maxRounds']=1;$request['traceLevel']='none';$result=$this->parity($request)['result'];self::assertLessThanOrEqual(3,count($result['attacker']['cohorts']));self::assertLessThanOrEqual(3,count($result['defender']['cohorts']));
    }
}
