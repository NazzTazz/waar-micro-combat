<?php

use App\Game\Combat\{CanonicalJson,CombatEngine,CombatReplay,ConsequencePolicy,DemoRequestFactory};
use App\Game\Random\AddressedRandom;
use App\Infrastructure\Combat\RustCombatResolver;
use PHPUnit\Framework\TestCase;

final class AddressedCombatTest extends TestCase
{
    public static function fixture(int $precision=25): array
    {
        $report=json_decode(file_get_contents(__DIR__."/fixtures/issue16-$precision.json"),true,512,JSON_THROW_ON_ERROR)['report'];
        // Browser JSON serialisation turns 1.0 into 1. Restore only the declared
        // floating-point targeting weights, without changing the archived hash.
        foreach($report['result']['ruleset']['targeting'] as &$row)foreach($row['weights'] as &$weight)$weight=(float)$weight;
        unset($row,$weight);
        return $report;
    }
    private function request(int $precision=25): array
    {
        $request=CombatReplay::request(self::fixture($precision));
        $request['stochasticEngineVersion']=AddressedRandom::VERSION;
        $request['armyIdentities']=['attacker'=>'B','defender'=>'A'];
        $request['consequences']['policyVersion']=ConsequencePolicy::ADDRESSED_VERSION;
        return $request;
    }
    private function rust(): RustCombatResolver {return new RustCombatResolver(dirname(__DIR__));}
    private function parity(array $request): array
    {
        $php=(new CombatEngine())->resolveRequest($request);$rust=$this->rust()->resolveRequest($request);
        self::assertSame(CanonicalJson::encode($php),CanonicalJson::encode($rust));
        return $php;
    }
    public function testCompleteHistoricalReportsStillReplayExactly(): void
    {
        foreach([25=>7,30=>11] as $p=>$wounded){
            $report=self::fixture($p);$actual=$this->parity(CombatReplay::request($report));
            self::assertSame(CanonicalJson::encode($report),CanonicalJson::encode($actual));
            self::assertSame($wounded,$actual['result']['attacker']['wounded']['spearman']);
        }
    }
    public function testIssue16HasStableOppositionAndNestedHitsWithExactReturn(): void
    {
        $low=$this->parity($this->request());$high=$this->parity($this->request(30));
        self::assertSame($low['result']['attacker'],$high['result']['attacker']);
        foreach([0,1] as $i){
            // Empty spearman rows legitimately display the edited ruleset value.
            self::assertSame($low['result']['rounds'][$i]['defenderAction']['matrix']['archer'],$high['result']['rounds'][$i]['defenderAction']['matrix']['archer']);
            self::assertSame($low['result']['rounds'][$i]['defenderAction']['attempts'],$high['result']['rounds'][$i]['defenderAction']['attempts']);
            self::assertGreaterThanOrEqual($low['result']['rounds'][$i]['attackerAction']['matrix']['spearman']['archer']['sampledHits'],$high['result']['rounds'][$i]['attackerAction']['matrix']['spearman']['archer']['sampledHits']);
        }
        self::assertSame($low,$this->parity($this->request()));
        self::assertSame($low,$this->parity(CombatReplay::request($low)));
    }
    public function testArmyIdentitySurvivesRolesIncludingAccuracyAndConsequences(): void
    {
        $request=$this->request();$request['consequences']['compressionPercent']=37;$request['consequences']['capturePercent']=25;
        foreach($request['ruleset']['units'] as &$unit){$unit['defendingEfficiency']='1';$unit['accuracySpread']='0.1';}unset($unit);
        $a=$this->parity($request);
        [$request['attacker'],$request['defender']]=[$request['defender'],$request['attacker']];
        $request['armyIdentities']=['attacker'=>'A','defender'=>'B'];
        $b=$this->parity($request);
        foreach(['attacker'=>'defender','defender'=>'attacker'] as $before=>$after){
            self::assertSame($a['result'][$before],$b['result'][$after]);
            self::assertSame($a['consequences'][$before],$b['consequences'][$after]);
            foreach($a['result']['rounds'] as $i=>$round){
                self::assertSame($round[$before.'Action'],$b['result']['rounds'][$i][$after.'Action']);
                self::assertSame($round['accuracy'][$before],$b['result']['rounds'][$i]['accuracy'][$after]);
            }
        }
        $request['ruleset']['units'][2]['defendingEfficiency']='2';
        $changed=$this->parity($request); // Archers now attack: their defensive factor does not apply.
        self::assertSame($b['result']['rounds'][0]['attackerAction'],$changed['result']['rounds'][0]['attackerAction']);
        [$request['attacker'],$request['defender']]=[$request['defender'],$request['attacker']];$request['armyIdentities']=['attacker'=>'B','defender'=>'A'];
        $defending=$this->parity($request);
        self::assertSame($a['result']['rounds'][0]['defenderAction']['matrix']['archer']['spearman']['sampledHits'],$defending['result']['rounds'][0]['defenderAction']['matrix']['archer']['spearman']['sampledHits']);
        self::assertSame('30',$defending['result']['rounds'][0]['defenderAction']['matrix']['archer']['spearman']['damagePerHit']);
    }
    public function testPhysicalLawsAcrossScalesAndDeathsAffectNextRoundOnly(): void
    {
        foreach([1,2,10,64,65,1000,1000000] as $scale){
            $r=$this->request();$r['attacker']['units']=['spearman'=>$scale];$r['defender']['units']=['archer'=>2*$scale];
            $r['ruleset']['maxRounds']=2;
            foreach($r['ruleset']['units'] as &$u){$u['structure']='10';$u['baseAccuracy']='1';$u['accuracySpread']='0';$u['strikesPerAttack']=1;$u['defendingEfficiency']='1';$u['attack']=$u['type']==='spearman'?'20':'0';}unset($u);
            $result=$this->parity($r)['result'];
            self::assertCount(2,$result['rounds']);
            self::assertSame(2*$scale,$result['rounds'][0]['defenderAction']['attempts']);
            self::assertSame($scale,$result['rounds'][1]['defenderAction']['attempts']);
            self::assertSame($scale,$result['rounds'][0]['attackerAction']['attempts']);
            self::assertSame('10',$result['rounds'][0]['attackerAction']['matrix']['spearman']['archer']['damagePerHit']);
            self::assertSame(2*$scale,$result['defender']['dead']['archer']);
            self::assertSame(0,$result['attacker']['dead']['spearman']);
        }
        $r=$this->request();$r['ruleset']['maxRounds']=2;$r['attacker']['units']=['spearman'=>10];$r['defender']['units']=['archer'=>10];
        foreach($r['ruleset']['units'] as &$u){$u['baseAccuracy']='1';$u['attack']='2';$u['structure']='100';$u['strikesPerAttack']=1;}unset($u);
        $r['ruleset']['woundDamageThreshold']='0';$wounded=$this->parity($r)['result'];
        self::assertSame(10,$wounded['attacker']['wounded']['spearman']);
        self::assertSame(10,$wounded['rounds'][1]['attackerAction']['attempts']);
    }
    public function testMixedCohortsTraceReplayAndBatchPartition(): void
    {
        $r=$this->request();$r['attacker']['units']=['soldier'=>31,'spearman'=>47,'knight'=>3];$r['defender']['units']=['archer'=>63,'soldier'=>29];$r['ruleset']['maxRounds']=4;
        $full=$this->parity($r);$r['traceLevel']='none';$none=$this->parity($r);
        foreach(['attacker','defender','winner','reason','decision'] as $key)self::assertSame($full['result'][$key],$none['result'][$key]);
        self::assertSame($none,$this->parity(CombatReplay::request($none)));
        $scenario=['id'=>'mixed','seedKey'=>0,'attacker'=>$r['attacker'],'defender'=>$r['defender'],'armyIdentities'=>$r['armyIdentities']];
        $batch=['schemaVersion'=>'waar-combat-batch-request/2','ruleset'=>$r['ruleset'],'baseSeed'=>42,'iterations'=>6,'startIteration'=>0,'totalIterations'=>6,'stochasticEngineVersion'=>AddressedRandom::VERSION,'consequences'=>$r['consequences'],'scenarios'=>[$scenario]];
        $whole=$this->rust()->resolveBatch($batch);$batch['iterations']=3;$first=$this->rust()->resolveBatch($batch);$batch['startIteration']=3;$last=$this->rust()->resolveBatch($batch);
        $w=$whole['scenarios'][0]['result'];$x=$first['scenarios'][0]['result'];$y=$last['scenarios'][0]['result'];
        $add=function($a,$b)use(&$add){if(is_array($a)){foreach($a as $k=>$v)$a[$k]=$add($v,$b[$k]);return $a;}return $a+$b;};
        foreach($w as $k=>$v){if(str_contains($k,'InitialByType'))self::assertSame($v,$x[$k]);else self::assertSame($v,$add($x[$k],$y[$k]),$k);}
        $sum=['attacker'=>array_fill(0,4,0),'defender'=>array_fill(0,4,0)];$rounds=0;
        for($seed=42;$seed<48;++$seed){$r['seed']=$seed;$result=$this->parity($r)['result'];$rounds+=count($result['rounds']);foreach(['attacker','defender'] as $side)foreach(['soldier','spearman','archer','knight'] as $i=>$t)$sum[$side][$i]+=$result[$side]['dead'][$t];}
        self::assertSame($rounds,$w['roundSum']);foreach($sum as $side=>$counts)self::assertSame($counts,$w[$side.'RawDeathsByType']);
        $batch['scenarios'][]=[...$scenario,'id'=>'another'];$forward=$this->rust()->resolveBatch($batch);$batch['scenarios']=array_reverse($batch['scenarios']);$reverse=$this->rust()->resolveBatch($batch);self::assertSame($forward['scenarios'],array_reverse($reverse['scenarios']));
    }
    public function testBadProtocolsAndIdentitiesAreRejectedByBothRuntimes(): void
    {
        $base=$this->request();$cases=[];
        foreach([null,'future',17] as $version)$cases[]=[...$base,'stochasticEngineVersion'=>$version];
        foreach([null,[],['attacker'=>'A','defender'=>'A'],['attacker'=>'attacker','defender'=>'defender'],['attacker'=>'A','defender'=>'B','extra'=>'C']] as $ids)$cases[]=[...$base,'armyIdentities'=>$ids];
        $missing=$base;unset($missing['armyIdentities']);$cases[]=$missing;
        $legacy=$base;unset($legacy['stochasticEngineVersion']);$cases[]=$legacy;
        $legacyNull=CombatReplay::request(self::fixture());$legacyNull['armyIdentities']=null;$cases[]=$legacyNull;
        $wrong=$base;$wrong['consequences']['policyVersion']=ConsequencePolicy::PROBABILISTIC_VERSION;$cases[]=$wrong;
        foreach($cases as $r)foreach([new CombatEngine(),$this->rust()] as $engine){try{$engine->resolveRequest($r);self::fail('Invalid protocol accepted');}catch(InvalidArgumentException|RuntimeException $e){self::assertNotEmpty($e->getMessage());}}
    }

    public function testCohortMergingAndInsertionOrderDoNotChangeAddressedImpacts(): void
    {
        $rules=\App\Game\Combat\Rules\CombatRuleset::fromArray($this->request()['ruleset']);
        $prepared=(new \App\Game\Combat\Preparation\CombatPreparation())->prepare($rules,[]);
        $archer=\App\Game\Army\UnitType::Archer;
        $cohort=static fn($structure,$n)=>new \App\Game\Combat\UnitCohort($archer,$structure,$n);
        $targets=[new \App\Game\Combat\CombatArmy([$cohort(20,2),$cohort(10,3)]),
            new \App\Game\Combat\CombatArmy([$cohort(10,1),$cohort(20,1),$cohort(10,2),$cohort(20,1)])];
        $source=\App\Game\Combat\CombatArmy::fromCounts(['spearman'=>4],$prepared);
        $results=[];
        foreach($targets as $target)$results[]=(new \App\Game\Combat\RoundResolver())->resolveAttacks($source,$target,$prepared,$rules,new \App\Game\Random\SeededRandomSource(42),['soldier'=>'0','spearman'=>'1','archer'=>'0','knight'=>'0'],false,new AddressedRandom(42,'B',1))->targetArmy->toArray($prepared);
        self::assertSame($results[0],$results[1]);
    }

    public function testProjectionAndWoundClassificationDoNotConsumePhysicsRandomness(): void
    {
        $r=$this->request();$base=$this->parity($r);
        foreach([[0,0],[37,25],[100,50]] as [$compression,$capture]){
            $r['consequences']['compressionPercent']=$compression;$r['consequences']['capturePercent']=$capture;
            self::assertSame($base['result'],$this->parity($r)['result']);
        }
        $r['ruleset']['woundDamageThreshold']='0';$allWounds=$this->parity($r)['result'];
        $r['ruleset']['woundDamageThreshold']='1';$noWounds=$this->parity($r)['result'];
        foreach(['rounds','winner','decision'] as $key)self::assertSame($allWounds[$key],$noWounds[$key]);
        foreach(['attacker','defender'] as $side){self::assertSame($allWounds[$side]['cohorts'],$noWounds[$side]['cohorts']);self::assertSame($allWounds[$side]['dead'],$noWounds[$side]['dead']);}
    }

    public function testElementaryCombatDistributionMatchesEnumeratedIndividualStrikes(): void
    {
        // Two independent Bernoulli(.25) strikes: enumerate their four outcomes.
        $expected=[0=>0.0,1=>0.0,2=>0.0];
        foreach([0,1] as $a)foreach([0,1] as $b)$expected[$a+$b]+=($a?.25:.75)*($b?.25:.75);
        $r=$this->request();$r['attacker']['units']=['spearman'=>2];$r['defender']['units']=['archer'=>10];$r['ruleset']['maxRounds']=1;
        foreach($r['ruleset']['units'] as &$u){$u['attack']=$u['type']==='spearman'?'20':'0';$u['structure']='10';$u['strikesPerAttack']=1;}unset($u);
        unset($r['consequences']);$counts=[0,0,0];$samples=1024;
        for($seed=0;$seed<$samples;++$seed){$r['seed']=$seed;$v=$this->parity($r)['result'];
            $dead=$v['defender']['dead']['archer'];++$counts[$dead];
            self::assertSame($dead,$v['rounds'][0]['attackerAction']['matrix']['spearman']['archer']['sampledHits']);
            self::assertSame(10-$dead,array_sum($v['defender']['healthy'])+array_sum($v['defender']['wounded']));
        }
        foreach($expected as $k=>$p)self::assertEqualsWithDelta($samples*$p,$counts[$k],7*sqrt($samples*$p*(1-$p))+1);
    }
}
