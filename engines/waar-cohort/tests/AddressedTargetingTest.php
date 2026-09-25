<?php

use App\Game\Combat\{CanonicalJson,CombatEngine,CombatReplay,DemoRequestFactory};
use App\Game\Random\AddressedRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

require_once dirname(__DIR__,3).'/autoload.php';

final class AddressedTargetingTest extends TestCase
{
    private const TYPES = ['soldier','spearman','archer','knight'];

    private function request(array $weights, array $counts=[1,1,1,0], int $attempts=1): array
    {
        $request=DemoRequestFactory::combat();
        $request['seed']=42;
        $request['ruleset']['maxRounds']=1;
        $request['attacker']=['units'=>['soldier'=>$attempts],'modifiers'=>[]];
        $request['defender']=['units'=>array_combine(self::TYPES,$counts),'modifiers'=>[]];
        $request['stochasticEngineVersion']=AddressedRandom::VERSION;
        $request['armyIdentities']=['attacker'=>'A','defender'=>'B'];
        unset($request['consequences']);
        foreach($request['ruleset']['units'] as &$unit){
            $unit['attack']='0';
            $unit['strikesPerAttack']=1;
        }
        unset($unit);
        foreach($request['ruleset']['targeting'] as &$row)$row['weights']=array_combine(self::TYPES,$weights);
        unset($row);
        return $request;
    }

    public static function runtimes(): array
    {
        return ['PHP'=>['php'],'Rust CLI'=>['rust']];
    }

    #[DataProvider('runtimes')]
    public function testReviewCaseCompletesEvenAfterAttemptsAreExhausted(string $kind): void
    {
        $runtime=new ProcessCohortRuntime(null,$kind);
        foreach([[1e16,2.9,.01,1.0],[1e16,.01,.01,1.0]] as $weights){
            $request=$this->request($weights);
            $report=$runtime->resolve($request);
            self::assertSame('round_limit',$report['result']['reason']);
            $row=$report['result']['rounds'][0]['attackerAction']['matrix']['soldier'];
            self::assertSame([1,0,0,0],array_map(static fn($type)=>$row[$type]['allocatedAttempts'],self::TYPES));
            $replay=CombatReplay::request((new CombatEngine())->resolveRequest($request));
            self::assertSame(CanonicalJson::encode($report),CanonicalJson::encode($runtime->resolve($replay)));
            if($weights[1]===2.9){
                $legacy=$request;
                unset($legacy['stochasticEngineVersion'],$legacy['armyIdentities']);
                self::assertSame('round_limit',$runtime->resolve($legacy)['result']['reason']);
            }
        }
    }

    public function testExtremeWeightsPreserveConditionalAllocationAndRuntimeParity(): void
    {
        $rust=new ProcessCohortRuntime(null,'rust');
        // All three rows have exactly the same effective masses 1:6:6.
        // The large row overflows both population * weight and their sum;
        // the tiny row uses subnormal preferences. The absent knight is ignored.
        foreach([[1.0,2.0,3.0,1e308],[5e307,1e308,1.5e308,1.0],[5e-324,1e-323,1.5e-323,1e308]] as $weights){
            foreach([0,1,42,2147483647] as $seed){
                $request=$this->request($weights,[1,3,2,0],65);
                $request['seed']=$seed;
                $php=(new CombatEngine())->resolveRequest($request);
                $native=$rust->resolve($request);
                // Historical PHP/serde exponential float encodings hash differently.
                // Compare every other field; ordinary full-hash replay coverage stays
                // in AddressedCombatTest. Do not rewrite either runtime's old hashes.
                unset($php['result']['replayHash'],$native['result']['replayHash']);
                self::assertSame(CanonicalJson::encode($php),CanonicalJson::encode($native));
                $random=new AddressedRandom($seed,'A',1);
                $soldiers=$random->binomial(65,1/13,'soldier','target/0/soldier');
                $spearmen=$random->binomial(65-$soldiers,.5,'soldier','target/0/spearman');
                $row=$php['result']['rounds'][0]['attackerAction']['matrix']['soldier'];
                self::assertSame([$soldiers,$spearmen,65-$soldiers-$spearmen,0],array_map(static fn($type)=>$row[$type]['allocatedAttempts'],self::TYPES));
                self::assertSame(65,array_sum(array_column($row,'allocatedAttempts')));
                self::assertSame([0,0,0,0],array_values($php['result']['defender']['dead']));
            }
        }
    }

    public function testExtremeWeightsAlsoWorkInBothBatchRuntimes(): void
    {
        $request=$this->request([1e16,2.9,.01,1.0]);
        $batch=['schemaVersion'=>'waar-combat-batch-request/2','ruleset'=>$request['ruleset'],
            'baseSeed'=>42,'iterations'=>3,'startIteration'=>0,'totalIterations'=>3,
            'stochasticEngineVersion'=>AddressedRandom::VERSION,
            'scenarios'=>[['id'=>'review-r1','seedKey'=>0,'attacker'=>$request['attacker'],
                'defender'=>$request['defender'],'armyIdentities'=>$request['armyIdentities']]]];
        $php=(new ProcessCohortRuntime(null,'php'))->batch($batch);
        $rust=(new ProcessCohortRuntime(null,'rust'))->batch($batch);
        self::assertSame(CanonicalJson::encode($php),CanonicalJson::encode($rust));
        self::assertSame(3,$rust['totalCombats']);
        self::assertSame(3,$rust['scenarios'][0]['result']['roundSum']);
        self::assertSame(3,$rust['scenarios'][0]['result']['defenderWins']);
        self::assertSame([0,0,0,0],$rust['scenarios'][0]['result']['defenderRawDeathsByType']);
    }
}
