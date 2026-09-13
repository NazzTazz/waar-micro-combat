<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\{EngineProfile, MonotypeMeasurementService, ProcessCohortRuntime, ConsequenceObjectives};

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopCasualtyMetricTest extends TestCase
{
    public function testEveryWoundedUnitCountsBeforeCaptureAndCompression(): void
    {
        $p=EngineProfile::defaults();
        $p['combat']['maxRounds']=1;
        foreach($p['units'] as &$unit){$unit['attack']='1';$unit['structure']='100';$unit['baseAccuracy']='1';$unit['accuracySpread']='0';$unit['capturable']=true;}unset($unit);
        foreach([0,8,100] as $compression){
            $p['combat']['lossCompressionPercent']=$compression;$p['combat']['capturePercent']=10;
            $native=(new MonotypeMeasurementService())->measure($p,'neutral',42,1);
            $php=(new MonotypeMeasurementService(new ProcessCohortRuntime(null,'php')))->measure($p,'neutral',42,1);
            self::assertSame($native['rows'],$php['rows']);
            foreach(array_slice($native['rows'],0,2) as $row){
                self::assertEquals(0,$row['rawLossRatio']);
                self::assertEquals(1,$row['rawWoundedRatio']);
                self::assertEquals(1,$row['rawCasualtyRatio']);
            }
            self::assertSame('rawCasualtyRatio',$native['context']['objectiveMetric']);
        }
        $zones=array_map(static fn($r)=>['id'=>$r['id'],'center'=>['x'=>$r['winRate'],'y'=>$r['rawCasualtyRatio']],'radii'=>['x'=>.05,'y'=>.1],'sourceFingerprint'=>$native['profileFingerprint'],'modelVersion'=>$native['modelVersion'],'context'=>$native['context']],$native['rows']);
        (new ConsequenceObjectives())->validate($p,$zones,'neutral',42,1);
        foreach($zones as &$zone)$zone['context']['objectiveMetric']='rawLossRatio';unset($zone);
        $this->expectException(\InvalidArgumentException::class);
        (new ConsequenceObjectives())->validate($p,$zones,'neutral',42,1);
    }
}
