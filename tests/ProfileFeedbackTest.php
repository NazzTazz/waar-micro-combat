<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;
use Waar\MicroCombat\Workshop\ProfileFeedbackService;
use Waar\MicroCombat\Workshop\ProfileInteractionExaminer;

require_once dirname(__DIR__).'/autoload.php';

final class ProfileFeedbackTest extends TestCase
{
    public function testExplainsMechanicalMeaningWithoutRunningCombat():void
    {
        $before=EngineProfile::defaults();$after=$before;$after['units']['soldier']['strikesPerAttack']=2;$after['units']['soldier']['defendingEfficiency']='1.5';$after['combat']['capturePercent']=20;
        $result=(new ProfileFeedbackService())->analyse($before,$after);
        self::assertCount(3,$result['changes']);self::assertStringContainsString('ne multiplie pas l’attaque',$result['changes'][0]['explanation']);self::assertStringContainsString('n’ajoute pas de résistance',$result['changes'][1]['explanation']);self::assertStringContainsString('blessés capturables',$result['changes'][2]['explanation']);self::assertNull($result['effects']);
    }

    public function testDetectsOnlyActiveSupportedInteractions():void
    {
        $base=EngineProfile::defaults();$current=$base;$current['units']['spearman']['structure']='40';$current['units']['spearman']['cost']=120;$current['units']['soldier']['attack']='8';$current['units']['soldier']['baseAccuracy']='0.1';
        $interactions=(new ProfileFeedbackService())->interactions($base,$current);
        self::assertCount(2,$interactions);self::assertSame('soldier-attack-baseAccuracy',$interactions[0]['id']);self::assertSame('spearman-structure-cost',$interactions[1]['id']);
        $current['units']['soldier']['attack']=$base['units']['soldier']['attack'];self::assertCount(1,(new ProfileFeedbackService())->interactions($base,$current));
    }

    public function testComparesRatesInPointsAndRefusesDifferentContext():void
    {
        $a=$this->measurement(.4,.2,5005);$b=$this->measurement(.6,.1,4004);$effects=(new ProfileFeedbackService())->compareMeasurements($a,$b);
        self::assertEqualsWithDelta(.2,$effects['deltas'][0]['winDelta'],.000001);self::assertSame(5005,$effects['deltas'][0]['beforeInitialCount']);self::assertSame(4004,$effects['deltas'][0]['afterInitialCount']);self::assertStringContainsString('+20 points',$effects['summaries'][0]);
        $b['context']['weather']='rain';$this->expectException(\InvalidArgumentException::class);(new ProfileFeedbackService())->compareMeasurements($a,$b);
    }

    public function testMeasuredStoryPrioritizesTheUnitChangedAndItsLosses():void
    {
        $beforeProfile=EngineProfile::defaults();$afterProfile=$beforeProfile;$afterProfile['units']['soldier']['attack']='8';
        $before=$this->measurement(.5,.10,5005);$before['rows'][0]=['id'=>'soldier-vs-spearman/attacker','scenarioId'=>'soldier-vs-spearman','side'=>'attacker','winRate'=>.5,'drawRate'=>0.0,'rawCasualtyRatio'=>.10,'initialCount'=>5005];$before['rows'][]=['id'=>'archer-vs-archer/attacker','scenarioId'=>'archer-vs-archer','side'=>'attacker','winRate'=>.5,'drawRate'=>0.0,'rawCasualtyRatio'=>.10,'initialCount'=>3080];
        $after=$before;$after['rows'][0]['rawCasualtyRatio']=.15;$after['rows'][1]['winRate']=.9;
        $result=(new ProfileFeedbackService())->analyse($beforeProfile,$afterProfile,$before,$after);
        self::assertSame(['soldier'],$result['effects']['focus']['unitTypes']);self::assertCount(1,$result['effects']['summaries']);self::assertStringContainsString('soldats attaquant les lanciers',$result['effects']['summaries'][0]);self::assertStringContainsString('Hors-combat bruts : 10 % → 15 %',$result['effects']['summaries'][0]);self::assertStringNotContainsString('archers',$result['effects']['summaries'][0]);
    }

    public function testInteractionBuildsFourExactVariantsAndRunsAtMost3200Combats():void
    {
        $runtime=new class implements CohortRuntime{public array$calls=[];public function resolve(array$request):array{throw new \LogicException();}public function provenance():array{return['kind'=>'test','transport'=>'memory','modelVersion'=>EngineProfile::MODEL_VERSION];}public function batch(array$request):array{$this->calls[]=$request;$scenarios=[];foreach($request['scenarios']as$s){$ai=array_values(['soldier'=>$s['attacker']['units']['soldier']??0,'spearman'=>$s['attacker']['units']['spearman']??0,'archer'=>$s['attacker']['units']['archer']??0,'knight'=>$s['attacker']['units']['knight']??0]);$di=array_values(['soldier'=>$s['defender']['units']['soldier']??0,'spearman'=>$s['defender']['units']['spearman']??0,'archer'=>$s['defender']['units']['archer']??0,'knight'=>$s['defender']['units']['knight']??0]);$zero=[0,0,0,0];$project=static fn(array$i):array=>array_map(static fn(int$n):array=>[$n*50,0,0,0],$i);$scenarios[]=['id'=>$s['id'],'result'=>['attackerWins'=>25,'defenderWins'=>25,'draws'=>0,'attackerInitialByType'=>$ai,'defenderInitialByType'=>$di,'attackerRawDeathsByType'=>$zero,'defenderRawDeathsByType'=>$zero,'attackerRawWoundedByType'=>$zero,'defenderRawWoundedByType'=>$zero,'attackerProjectedByType'=>$project($ai),'defenderProjectedByType'=>$project($di)]];}return['schemaVersion'=>'waar-combat-batch-result/2','unitOrder'=>array_keys(EngineProfile::UNIT_COSTS),'projectedCategoryOrder'=>['healthy','wounded','dead','prisoners'],'iterationRange'=>['start'=>0,'endExclusive'=>50,'total'=>50,'complete'=>true],'totalCombats'=>800,'scenarios'=>$scenarios];}};
        $base=EngineProfile::defaults();$current=$base;$current['units']['spearman']['structure']='40';$current['units']['spearman']['cost']=120;$service=new ProfileFeedbackService();$interaction=$service->interactions($base,$current)[0];$examiner=new ProfileInteractionExaminer(new MonotypeMeasurementService($runtime),$service);$variants=$examiner->variants($current,$interaction);
        self::assertSame($base['units']['spearman']['structure'],$variants['P0']['units']['spearman']['structure']);self::assertSame($base['units']['spearman']['cost'],$variants['P0']['units']['spearman']['cost']);self::assertSame('40',$variants['PA']['units']['spearman']['structure']);self::assertSame($base['units']['spearman']['cost'],$variants['PA']['units']['spearman']['cost']);self::assertSame($base['units']['spearman']['structure'],$variants['PB']['units']['spearman']['structure']);self::assertSame(120,$variants['PB']['units']['spearman']['cost']);
        $result=$examiner->examine($base,$current,$interaction['id']);self::assertSame(['P0','PA','PB','PAB'],array_keys($result['variants']));self::assertCount(16,$result['comparisons']);self::assertStringContainsString('ne change pas',$result['comparisons']['soldier-vs-soldier']['message']);self::assertCount(4,$runtime->calls);foreach($runtime->calls as$request){self::assertSame(50,$request['iterations']);self::assertCount(16,$request['scenarios']);}self::assertSame(3200,array_sum(array_column(array_column($result['variants'],'batch'),'totalCombats')));
    }

    private function measurement(float$win,float$casualties,int$initial):array{return['context'=>['weather'=>'neutral','baseSeed'=>42,'iterations'=>50,'budget'=>400400,'modelVersion'=>EngineProfile::MODEL_VERSION,'runtime'=>['kind'=>'rust','transport'=>'process-jsonl','modelVersion'=>EngineProfile::MODEL_VERSION]],'rows'=>[['id'=>'soldier-vs-spearman/attacker','scenarioId'=>'soldier-vs-spearman','side'=>'attacker','winRate'=>$win,'drawRate'=>.1,'rawCasualtyRatio'=>$casualties,'initialCount'=>$initial]]];}
}
