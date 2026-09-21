<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Waar\MicroCombat\Workshop\{DuelService,EngineProfile,ProcessCohortRuntime,ProfileValidationException};

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopDuelLimitsTest extends TestCase
{
    public static function woundThresholds(): array
    {
        return ['historical zero'=>['0',1000000], 'new default'=>['0.2',0]];
    }

    #[DataProvider('woundThresholds')]
    public function testMillionUnitArmiesAndWoundedEconomyHaveRuntimeParity(string $threshold, int $rawWounded): void
    {
        $p=EngineProfile::defaults();$p['combat']['maxRounds']=1;$p['combat']['capturePercent']=50;
        $p['combat']['woundDamageThreshold']=$threshold;
        foreach($p['units'] as &$u){$u['attack']='1';$u['structure']='100';$u['baseAccuracy']='1';$u['accuracySpread']='0';$u['cost']=400400;$u['capturable']=true;}unset($u);
        foreach([0,8,100] as $compression){
            $p['combat']['lossCompressionPercent']=$compression;
            $request=['profile'=>$p,'armies'=>['A'=>['soldier'=>1000000],'B'=>['soldier'=>1000000]],'seed'=>42];
            $native=(new DuelService())->simulate($request);
            $php=(new DuelService(new ProcessCohortRuntime(null,'php')))->simulate($request);
            foreach($native['directions'] as $i=>$direction){
                self::assertEquals($direction['consequences'],$php['directions'][$i]['consequences']);
                foreach(['attacker','defender'] as $side){
                    $data=$direction['consequences'][$side];$unit=$data['types']['soldier'];
                    self::assertSame($rawWounded,$unit['raw']['wounded']);
                    self::assertSame(0,$unit['projected']['dead']);
                    self::assertSame($unit['projected']['wounded']*400400,$data['economicLoss']);
                    self::assertEquals($unit['projected']['wounded']/10000,(float)$data['economicLossPercent']);
                    if($compression>0&&$rawWounded>0)self::assertGreaterThan(0,$data['economicLoss']);
                    if($rawWounded===0){
                        self::assertSame(0,$unit['projected']['wounded']);
                        self::assertSame(0,$unit['projected']['prisoners']);
                        self::assertSame(0,$data['economicLoss']);
                    }
                }
            }
        }
    }

    public function testCountsAboveGuardrailAreRejected(): void
    {
        $this->expectException(ProfileValidationException::class);
        (new DuelService())->simulate(['profile'=>EngineProfile::defaults(),'armies'=>['A'=>['soldier'=>1000001],'B'=>['soldier'=>1]],'seed'=>42]);
    }

    public function testSummaryRejectsCountsAboveOneMillionPerType(): void
    {
        $this->expectException(ProfileValidationException::class);
        (new DuelService())->simulate(['profile'=>EngineProfile::defaults(),'armies'=>['A'=>['soldier'=>1],'B'=>['knight'=>1000001]],'seed'=>42],true);
    }
}
