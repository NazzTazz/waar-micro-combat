<?php

use App\Game\Combat\CanonicalJson;
use App\Game\Combat\CombatEngine;
use App\Game\Combat\CombatReplay;
use App\Game\Combat\DemoRequestFactory;
use App\Game\Combat\ConsequencePolicy;
use App\Game\Random\ConsequenceSampler;
use App\Infrastructure\Combat\RustCombatResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptanceRegressionTest extends TestCase
{
    public function testProbabilisticRc1ParityReplayAndRawIndependence(): void
    {
        $fixture=require __DIR__.'/fixtures/rc1.php';$php=new CombatEngine();$rust=$this->rust();
        $captures=$losses=0;
        foreach([0,1,2,42,9381,2147483647] as $seed){
            $request=$fixture(['soldier'=>300,'spearman'=>75,'archer'=>25],['soldier'=>175,'knight'=>15],$seed);
            $legacy=$php->resolveRequest($request);
            self::assertSame(CanonicalJson::encode($legacy),CanonicalJson::encode($rust->resolveRequest($request)));
            $request['consequences']['policyVersion']=ConsequencePolicy::VERSION;
            self::assertSame($legacy,$php->resolveRequest($request)); // absent version remains /2
            $request['consequences']['policyVersion']=ConsequencePolicy::PROBABILISTIC_VERSION;
            $full=$php->resolveRequest($request);
            self::assertSame(CanonicalJson::encode($full),CanonicalJson::encode($rust->resolveRequest($request)));
            self::assertSame($legacy['result'],$full['result']);
            self::assertSame(ConsequenceSampler::VERSION,$full['consequences']['samplingProtocol']);
            foreach(['attacker','defender'] as $side)foreach($full['consequences'][$side]['types'] as $row){$captures+=$row['projected']['prisoners'];$losses+=$row['projected']['dead']+$row['projected']['wounded'];}
            $request['traceLevel']='none';$none=$php->resolveRequest($request);
            self::assertSame(CanonicalJson::encode($none),CanonicalJson::encode($rust->resolveRequest($request)));
            $normalize=static function(array $report):array{
                unset($report['result']['snapshot']['traceLevel'],$report['result']['replayHash'],$report['consequences']['rawResult']);
                foreach($report['result']['rounds'] as &$round)unset($round['attackerAction'],$round['defenderAction']);unset($round);
                return $report;
            };
            self::assertSame(CanonicalJson::encode($normalize($full)),CanonicalJson::encode($normalize($none)));
            $request['consequences']['compressionPercent']=100;$request['consequences']['capturePercent']=50;
            $changed=$php->resolveRequest($request);self::assertSame($none['result'],$changed['result']);
            self::assertSame(CanonicalJson::encode($changed),CanonicalJson::encode($rust->resolveRequest($request)));
            unset($request['consequences']);$bare=$php->resolveRequest($request);self::assertSame($none['result'],$bare['result']);
            self::assertSame(CanonicalJson::encode($bare),CanonicalJson::encode($rust->resolveRequest($request)));
            if($seed===42){$replay=CombatReplay::request($full);self::assertSame(ConsequencePolicy::PROBABILISTIC_VERSION,$replay['consequences']['policyVersion']);self::assertSame($full,$php->resolveRequest($replay));self::assertSame(CanonicalJson::encode($full),CanonicalJson::encode($rust->resolveRequest($replay)));}
        }
        self::assertGreaterThan(0,$captures);self::assertGreaterThan(0,$losses);
    }

    public function testProbabilisticBatchMatchesIndividualsAndRecombinedRanges(): void
    {
        $fixture=require __DIR__.'/fixtures/rc1.php';$request=$fixture(['soldier'=>3,'spearman'=>1,'knight'=>18],['soldier'=>1000]);
        $request['traceLevel']='none';$request['consequences']['policyVersion']=ConsequencePolicy::PROBABILISTIC_VERSION;
        // Cover both existing seed derivations, a role swap, and widened totals.
        $batch=['schemaVersion'=>'waar-combat-batch-request/2','ruleset'=>$request['ruleset'],'baseSeed'=>42,'iterations'=>6,'totalIterations'=>6,'consequences'=>$request['consequences'],
            'scenarios'=>[['id'=>'small-forward','seedKey'=>0,'attacker'=>$request['attacker'],'defender'=>$request['defender']],['id'=>'small-reverse','attacker'=>$request['defender'],'defender'=>$request['attacker']]]];
        $rust=$this->rust();$full=$rust->resolveBatch($batch);
        if($path=getenv('WAAR_ISSUE12_EVIDENCE'))file_put_contents($path.'.batch',json_encode($full,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        self::assertSame(CanonicalJson::encode(['policyVersion'=>ConsequencePolicy::PROBABILISTIC_VERSION,'samplingProtocol'=>ConsequenceSampler::VERSION,'compressionPercent'=>5,'capturePercent'=>24]),CanonicalJson::encode($full['consequenceProvenance']));
        $php=new CombatEngine();$retained=0;
        foreach($batch['scenarios'] as $index=>$scenario){
            $expected=[];
            for($iteration=0;$iteration<6;$iteration++){
                if(isset($scenario['seedKey']))$seed=(42+$scenario['seedKey']*1000003+$iteration)%2147483647;
                else $seed=unpack('N',substr(hash('sha256',"42\0".$scenario['id']."\0".$iteration,true),0,4))[1]&0x7fffffff;
                $individual=$request;$individual['seed']=$seed;$individual['attacker']=$scenario['attacker'];$individual['defender']=$scenario['defender'];
                $report=$php->resolveRequest($individual);self::assertSame(CanonicalJson::encode($report),CanonicalJson::encode($rust->resolveRequest($individual)));
                foreach(['attacker','defender'] as $side)foreach(['soldier','spearman','archer','knight'] as $i=>$type)foreach(['healthy','wounded','dead','prisoners'] as $k=>$category){
                    $expected[$side][$i][$k]=($expected[$side][$i][$k]??0)+$report['consequences'][$side]['types'][$type]['projected'][$category];
                    if($type==='knight'&&$category!=='healthy')$retained+=$report['consequences'][$side]['types'][$type]['projected'][$category];
                }
            }
            foreach($expected as $side=>$totals)self::assertSame($totals,$full['scenarios'][$index]['result'][$side.'ProjectedByType']);
        }
        self::assertGreaterThan(0,$retained,'The fixed small-cohort series must no longer be systematically immune.');
        $batch['iterations']=3;$first=$rust->resolveBatch($batch);$batch['startIteration']=3;$second=$rust->resolveBatch($batch);
        $add=static function($a,$b)use(&$add){return is_array($a)?array_map($add,$a,$b):$a+$b;};
        foreach($full['scenarios'] as $i=>$scenario)foreach($scenario['result'] as $key=>$value){
            if(str_contains($key,'InitialByType'))continue;
            self::assertSame($value,$add($first['scenarios'][$i]['result'][$key],$second['scenarios'][$i]['result'][$key]),$key);
        }
    }

    public function testProbabilisticPolicyValidationAndLargeTotals(): void
    {
        $request=DemoRequestFactory::combat('none');$request['ruleset']['maxRounds']=1;
        foreach($request['ruleset']['units'] as &$unit){$unit['attack']='1';$unit['structure']='100';$unit['baseAccuracy']='1';$unit['accuracySpread']='0';$unit['defendingEfficiency']='1';}unset($unit);
        $request['attacker']=['units'=>['soldier'=>4294967295],'modifiers'=>[]];$request['defender']=$request['attacker'];
        $request['consequences']=['policyVersion'=>ConsequencePolicy::PROBABILISTIC_VERSION,'compressionPercent'=>100,'capturePercent'=>50];
        $report=(new CombatEngine())->resolveRequest($request);self::assertSame(CanonicalJson::encode($report),CanonicalJson::encode($this->rust()->resolveRequest($request)));
        $batch=['schemaVersion'=>'waar-combat-batch-request/2','ruleset'=>$request['ruleset'],'baseSeed'=>$request['seed'],'iterations'=>2,'consequences'=>$request['consequences'],'scenarios'=>[['id'=>'large','seedKey'=>0,'attacker'=>$request['attacker'],'defender'=>$request['defender']]]];
        $large=$this->rust()->resolveBatch($batch);
        self::assertSame(8589934590,array_sum($large['scenarios'][0]['result']['attackerProjectedByType'][0]));
        foreach(['unknown',null,3] as $invalid){
            $request['consequences']['policyVersion']=$invalid;$batch['consequences']['policyVersion']=$invalid;
            foreach([fn()=>(new CombatEngine())->resolveRequest($request),fn()=>$this->rust()->resolveRequest($request),fn()=>$this->rust()->resolveBatch($batch)] as $call){
                try{$call();self::fail('Invalid policy accepted');}catch(InvalidArgumentException|RuntimeException $e){self::assertNotEmpty($e->getMessage());}
            }
        }
    }

    public function testRc1FieldCasesPreserveRawResults(): void
    {
        $fixture=require __DIR__.'/fixtures/rc1.php';$pairs=[];
        $small=[
            'K10-S'=>[['knight'=>10],['soldier'=>1000]],
            'K18L-S'=>[['soldier'=>3,'spearman'=>1,'knight'=>18],['soldier'=>1000]],
            'K18L-L'=>[['soldier'=>3,'spearman'=>1,'knight'=>18],['soldier'=>6,'spearman'=>142]],
            'R-C'=>[['soldier'=>300,'spearman'=>75,'archer'=>25],['soldier'=>175,'knight'=>15]],
        ];
        foreach($small as $id=>[$a,$d]){$pairs[$id]=[$a,$d];$pairs[$id.'-reverse']=[$d,$a];}
        foreach([200000=>2,400000=>1] as $budget=>$divisor){
            $r=['soldier'=>intdiv(12000,$divisor),'spearman'=>intdiv(3000,$divisor),'archer'=>intdiv(1000,$divisor)];
            $c=['soldier'=>intdiv(7000,$divisor),'knight'=>intdiv(600,$divisor)];
            $e=['soldier'=>intdiv(2,$divisor),'archer'=>intdiv(5714,$divisor)];
            foreach(['R-C'=>[$r,$c],'C-E'=>[$c,$e],'E-R'=>[$e,$r]] as $id=>[$a,$d]){$pairs[$budget.'-'.$id]=[$a,$d];$pairs[$budget.'-'.$id.'-reverse']=[$d,$a];}
        }
        $evidence=[];$php=new CombatEngine();$rust=$this->rust();
        foreach($pairs as $id=>[$a,$d]){
            $request=$fixture($a,$d);$request['traceLevel']='none';
            $old=$php->resolveRequest($request);self::assertSame(CanonicalJson::encode($old),CanonicalJson::encode($rust->resolveRequest($request)));
            $request['consequences']['policyVersion']=ConsequencePolicy::PROBABILISTIC_VERSION;
            $new=$php->resolveRequest($request);self::assertSame(CanonicalJson::encode($new),CanonicalJson::encode($rust->resolveRequest($request)));
            self::assertSame($old['result'],$new['result'],$id);
            $evidence[$id]=['seed'=>42,'winner'=>$new['result']['winner'],'rounds'=>count($new['result']['rounds']),'old'=>$old['consequences'],'new'=>$new['consequences']];
        }
        if($path=getenv('WAAR_ISSUE12_EVIDENCE'))file_put_contents($path,json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private function rust(): RustCombatResolver
    {
        return new RustCombatResolver(dirname(__DIR__));
    }

    public function testAllDemoMonotypesMatchPhpIncludingProjectedBatchTotals(): void
    {
        $batch = DemoRequestFactory::batch(3);
        $native = $this->rust()->resolveBatch($batch);
        $php = new CombatEngine();
        foreach ($batch['scenarios'] as $index => $scenario) {
            $expected = ['attackerWins'=>0, 'defenderWins'=>0, 'draws'=>0, 'roundSum'=>0];
            foreach (['attacker', 'defender'] as $side) {
                $expected[$side.'RawDeathsByType'] = array_fill(0, 4, 0);
                $expected[$side.'RawWoundedByType'] = array_fill(0, 4, 0);
                $expected[$side.'ProjectedByType'] = array_fill(0, 4, array_fill(0, 4, 0));
            }
            for ($iteration = 0; $iteration < 3; ++$iteration) {
                $request = [
                    'schemaVersion'=>CombatEngine::REQUEST_SCHEMA, 'ruleset'=>$batch['ruleset'],
                    'attacker'=>$scenario['attacker'], 'defender'=>$scenario['defender'],
                    'seed'=>($batch['baseSeed'] + $scenario['seedKey'] * 1000003 + $iteration) % 2147483647,
                    'traceLevel'=>$iteration % 2 === 0 ? 'none' : 'full', 'consequences'=>$batch['consequences'],
                ];
                $report = $php->resolveRequest($request);
                self::assertSame(CanonicalJson::encode($report), CanonicalJson::encode($this->rust()->resolveRequest($request)), $scenario['id']);
                $winner = $report['result']['winner'];
                ++$expected[$winner === null ? 'draws' : $winner.'Wins'];
                $expected['roundSum'] += count($report['result']['rounds']);
                foreach (['attacker', 'defender'] as $side) {
                    foreach (['soldier', 'spearman', 'archer', 'knight'] as $typeIndex => $type) {
                        $expected[$side.'RawWoundedByType'][$typeIndex] += $report['result'][$side]['wounded'][$type];
                        $expected[$side.'RawDeathsByType'][$typeIndex] += $report['result'][$side]['dead'][$type];
                        foreach (['healthy', 'wounded', 'dead', 'prisoners'] as $categoryIndex => $category) {
                            $expected[$side.'ProjectedByType'][$typeIndex][$categoryIndex] += $report['consequences'][$side]['types'][$type]['projected'][$category];
                        }
                    }
                }
            }
            foreach ($expected as $key => $value) self::assertSame($value, $native['scenarios'][$index]['result'][$key], $scenario['id'].': '.$key);
        }
    }

    public static function largePopulations(): iterable
    {
        yield 'dead' => [50000000, '100', [0, 0, 50000000, 0]];
        yield 'wounded and captured' => [500000000, '1', [0, 250000000, 0, 250000000]];
    }

    #[DataProvider('largePopulations')]
    public function testLargeConsequencesMatchInDuelAndBatch(int $count, string $attack, array $categories): void
    {
        $request = DemoRequestFactory::combat('none');
        $request['attacker'] = ['units'=>['soldier'=>$count], 'modifiers'=>[]];
        $request['defender'] = $request['attacker'];
        $request['ruleset']['maxRounds'] = 1;
        foreach ($request['ruleset']['units'] as &$unit) {
            $unit['attack'] = $attack;
            $unit['structure'] = '100';
            $unit['baseAccuracy'] = '1';
            $unit['accuracySpread'] = '0';
            $unit['defendingEfficiency'] = '1';
        }
        unset($unit);
        $request['consequences'] = ['compressionPercent'=>100, 'capturePercent'=>50];
        $php = (new CombatEngine())->resolveRequest($request);
        self::assertSame(CanonicalJson::encode($php), CanonicalJson::encode($this->rust()->resolveRequest($request)));
        $projected = $php['consequences']['attacker']['types']['soldier']['projected'];
        self::assertSame($categories, array_map(static fn($key) => $projected[$key], ['healthy','wounded','dead','prisoners']));
        $batch = DemoRequestFactory::batch(1);
        $batch['ruleset'] = $request['ruleset'];
        $batch['consequences'] = $request['consequences'];
        $batch['scenarios'] = [['id'=>'large', 'seedKey'=>0, 'attacker'=>$request['attacker'], 'defender'=>$request['defender']]];
        $native = $this->rust()->resolveBatch($batch);
        self::assertSame($categories, $native['scenarios'][0]['result']['attackerProjectedByType'][0]);
    }

    public static function invalidPercentages(): iterable
    {
        yield ['compressionPercent', 101];
        yield ['capturePercent', 51];
    }

    #[DataProvider('invalidPercentages')]
    public function testBatchRejectsInvalidConsequences(string $key, int $value): void
    {
        $batch = DemoRequestFactory::batch(1);
        $batch['consequences'][$key] = $value;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compression must be 0..100 and capture 0..50 percent');
        $this->rust()->resolveBatch($batch);
    }

    public function testSerializedReportsReplayWithoutExternalRules(): void
    {
        foreach (['none', 'full'] as $trace) {
            $request = DemoRequestFactory::combat($trace);
            $request['ruleset']['maxRounds'] = 7;
            $request['ruleset']['surrender'] = ['enabled'=>true, 'deadRatio'=>'0.37'];
            $request['ruleset']['tieBreak'] = ['criterion'=>'structure', 'equality'=>'draw'];
            foreach ([new CombatEngine(), $this->rust()] as $engine) {
                $saved = json_decode(json_encode($engine->resolveRequest($request), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $replay = CombatReplay::request($saved);
                foreach ([new CombatEngine(), $this->rust()] as $replayer) {
                    self::assertSame(CanonicalJson::encode($saved), CanonicalJson::encode($replayer->resolveRequest($replay)));
                }
            }
        }
    }

    public function testReplayRejectsTamperedRules(): void
    {
        $report = (new CombatEngine())->resolveRequest(DemoRequestFactory::combat());
        $report['result']['ruleset']['maxRounds']++;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Replay inputs do not match');
        CombatReplay::request($report);
    }

    public function testReplayCliAcceptsSavedDemoAndRejectsAlteredResult(): void
    {
        $report = (new CombatEngine())->resolveRequest(DemoRequestFactory::combat());
        $path = tempnam(sys_get_temp_dir(), 'cohort-replay-');
        self::assertNotFalse($path);
        try {
            foreach ([false, true] as $tampered) {
                if ($tampered) $report['result']['winner'] = 'invalid';
                file_put_contents($path, CanonicalJson::encode(['combat'=>$report]));
                $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/replay.php', $path],
                    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($tampered ? 1 : 0, proc_close($process), $error);
                if ($tampered) self::assertStringContainsString('Replay differs', $error);
                else self::assertSame(CanonicalJson::encode($report), CanonicalJson::encode(json_decode($output, true, 512, JSON_THROW_ON_ERROR)));
            }
        } finally {
            unlink($path);
        }
    }
}
