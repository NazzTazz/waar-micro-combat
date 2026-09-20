<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\{EngineProfile, MonotypeComparisonService, MonotypeMechanics, MonotypeMeasurementService, CohortRequestFactory, ProcessCohortRuntime};

require_once dirname(__DIR__).'/autoload.php';

final class MonotypeComparisonTest extends TestCase
{
    public function testPinnedCaseIgnoresLargeUnrelatedDeltasAndSeparatesLossesFromVictories(): void
    {
        $p=EngineProfile::defaults();$before=$this->measurement($p);$after=$before;
        $after['rows'][0]['rawLossRatio']=.2;$after['rows'][0]['rawWoundedRatio']=.1; // total casualties unchanged
        $after['rows'][20]['winRate']=.9; // archers vs archers, irrelevant to pinned case
        $result=(new MonotypeComparisonService())->compare($p,$p,$before,$after,'soldier-vs-soldier');
        self::assertStringContainsString('Même nombre de victoires',$result['summary']);
        self::assertStringContainsString('morts ou les blessés bruts ont changé',$result['summary']);
        self::assertStringNotContainsString('archers',$result['summary']);
        self::assertEqualsWithDelta(.1,$result['sides']['attacker']['deltas']['rawLossRatio'],1e-9);
    }

    public function testCaptureCompressionAndDrawsAreMeasuredEvenWithoutRawChanges(): void
    {
        $p=EngineProfile::defaults();$q=$p;$q['combat']['capturePercent']=20;
        $before=$this->measurement($p);$after=$this->measurement($q);
        $after['rows'][1]['captureRatio']=.04;$after['rows'][1]['woundedRatio']=.06;
        $result=(new MonotypeComparisonService())->compare($p,$q,$before,$after,'soldier-vs-soldier');
        self::assertStringContainsString('conséquences après compression et capture ont changé',$result['summary']);
        self::assertEqualsWithDelta(.04,$result['sides']['defender']['deltas']['captureRatio'],1e-9);
        $after['rows'][0]['drawRate']=.2;$after['rows'][1]['drawRate']=.2;
        self::assertStringContainsString('matchs nuls a changé',(new MonotypeComparisonService())->compare($p,$q,$before,$after,'soldier-vs-soldier')['summary']);
    }

    public function testReverseCounterCaseIsComparedAndCostShowsActualPopulations(): void
    {
        $p=EngineProfile::defaults();$q=$p;$q['units']['soldier']['cost']=100;
        $q['relations']=[['acting'=>'soldier','target'=>'spearman','factor'=>'2']];
        $before=$this->measurement($p);$after=$this->measurement($q);
        $after['rows'][8]['winRate']=.7;$after['rows'][9]['winRate']=.3;
        $result=(new MonotypeComparisonService())->compare($p,$q,$before,$after,'spearman-vs-soldier');
        self::assertStringContainsString('répartition des victoires a changé',$result['summary']);
        self::assertSame(5005,$result['sides']['defender']['before']['initialCount']);
        self::assertSame(4004,$result['sides']['defender']['after']['initialCount']);
    }

    public function testRejectsPartialDuplicateWrongProfileAndDifferentContextMeasurements(): void
    {
        $p=EngineProfile::defaults();$valid=$this->measurement($p);
        $bad=$valid;$bad['rows']=array_slice($bad['rows'],0,1);$invalid=[$bad];
        $bad=$valid;$bad['rows'][1]=$bad['rows'][0];$invalid[]=$bad;
        $bad=$valid;$bad['profileFingerprint']='another-profile';$invalid[]=$bad;
        $bad=$valid;$bad['context']['weather']='rain';$invalid[]=$bad;
        $bad=$valid;$bad['batch']['iterationRange']['complete']=false;$invalid[]=$bad;
        foreach($invalid as$bad){try{(new MonotypeComparisonService())->compare($p,$p,$valid,$bad,'soldier-vs-soldier');self::fail('Invalid measurement accepted');}catch(\InvalidArgumentException $e){self::assertNotEmpty($e->getMessage());}}
    }

    public function testMechanicsShowUnchangedKillThresholdAndMatchNativeTrace(): void
    {
        $p=EngineProfile::defaults();$p['units']['knight']['attack']='24';$p['units']['knight']['defendingEfficiency']='0.5';$p['units']['archer']['structure']='10';
        $profile=EngineProfile::fromArray($p);$service=new MonotypeMechanics();
        $mechanics=$service->describe($profile,'neutral','knight-vs-archer')['attacker'];
        self::assertSame('24',$mechanics['damageWhenAttacking']);self::assertSame('12',$mechanics['damageWhenDefending']);
        self::assertSame(1,$mechanics['hitsWhenAttacking']);self::assertSame(1,$mechanics['hitsWhenDefending']);
        // Exercise rounding, weather, directed counter, strikes and defensive role against Rust.
        $p['units']['knight']['attack']='23.000001';$p['units']['knight']['strikesPerAttack']=3;
        $p['weather']['rain']['knight']=['attack'=>'0.666667','baseAccuracy'=>'0.75'];
        $p['relations']=[['acting'=>'knight','target'=>'archer','factor'=>'1.333333']];
        $profile=EngineProfile::fromArray($p);
        foreach(['knight-vs-archer','archer-vs-knight']as$scenario){
            [$a,$d]=MonotypeMechanics::types($scenario);
            $result=(new ProcessCohortRuntime())->resolve((new CohortRequestFactory())->combat($profile,[$a=>1],[$d=>1],42,'rain','rain'));
            $described=$service->describe($profile,'rain',$scenario);
            foreach(['attacker'=>[$a,$d],'defender'=>[$d,$a]]as$side=>[$acting,$target]){
                $cell=$result['result']['rounds'][0][$side.'Action']['matrix'][$acting][$target];
                self::assertSame($cell['attackPerStrike'],$described[$side]['attackPerStrike']);
                self::assertSame($cell['damagePerHit'],$described[$side]['damagePerHit']);
            }
        }
        $p['units']['knight']['attack']='0';
        self::assertNull($service->describe(EngineProfile::fromArray($p),'neutral','knight-vs-archer')['attacker']['hitsToKillIntact']);
    }

    private function measurement(array $p): array
    {
        $profile=EngineProfile::fromArray($p);$rows=[];
        foreach(array_keys(EngineProfile::UNIT_COSTS)as$a)foreach(array_keys(EngineProfile::UNIT_COSTS)as$d)foreach(['attacker','defender']as$side){
            $rows[]=['id'=>"$a-vs-$d/$side",'scenarioId'=>"$a-vs-$d",'attackerType'=>$a,'defenderType'=>$d,'side'=>$side,'iterations'=>50,'initialCount'=>intdiv(400400,$profile->costs()[$side==='attacker'?$a:$d]),'winRate'=>.5,'drawRate'=>0.0,'rawLossRatio'=>.1,'rawWoundedRatio'=>.2,'rawCasualtyRatio'=>.3,'appliedLossRatio'=>.1,'woundedRatio'=>.1,'captureRatio'=>0.0,'freeRatio'=>.8];
        }
        return ['schemaVersion'=>'waar-monotype-consequence-observations/0.2','profileFingerprint'=>$profile->semanticFingerprint(),'context'=>['weather'=>'neutral','baseSeed'=>42,'iterations'=>50,'budget'=>400400,'modelVersion'=>EngineProfile::MODEL_VERSION,'runtime'=>['kind'=>'rust','transport'=>'process-jsonl','modelVersion'=>EngineProfile::MODEL_VERSION]],'batch'=>['totalCombats'=>800,'iterationRange'=>['start'=>0,'endExclusive'=>50,'total'=>50,'complete'=>true]],'rows'=>$rows];
    }
}
