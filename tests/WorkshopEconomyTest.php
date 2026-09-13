<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\{BoundedProfileSearch, ConsequenceObjectives, DuelService, EngineProfile, MonotypeMeasurementService, T27Editor};

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopEconomyTest extends TestCase
{
    public function testConfiguredCostsDriveDuelMeasurementAndEditor(): void
    {
        $profile=EngineProfile::defaults();
        $originalFingerprint=EngineProfile::fromArray($profile)->semanticFingerprint();
        $profile['units']['soldier']['cost']=160;
        $profile['units']['soldier']['capturable']=true;
        $profile['combat']['capturePercent']=10;
        $parsed=EngineProfile::fromArray($profile);
        self::assertSame(160,$parsed->toArray()['units']['soldier']['cost']);
        self::assertNotSame($originalFingerprint,$parsed->semanticFingerprint());
        $duel=(new DuelService())->simulate(['profile'=>$profile,'armies'=>['A'=>['soldier'=>100],'B'=>['soldier'=>120]],'seed'=>42]);
        self::assertSame(['A'=>16000,'B'=>19200],$duel['costs']);
        foreach($duel['directions'] as $direction) foreach(['attacker','defender'] as $sideName){$side=$direction['consequences'][$sideName];
            $unit=$side['types']['soldier'];
            self::assertSame(($unit['projected']['dead']+$unit['projected']['wounded'])*160,$side['economicLoss']);
        }
        $measurement=(new MonotypeMeasurementService())->measure($profile,'neutral',42,1);
        $zones=array_map(static fn($r)=>['id'=>$r['id'],'scenarioId'=>$r['scenarioId'],'side'=>$r['side'],'center'=>['x'=>$r['winRate'],'y'=>$r['rawLossRatio']],'radii'=>['x'=>.05,'y'=>.1],'sourceFingerprint'=>$measurement['profileFingerprint'],'modelVersion'=>$measurement['modelVersion'],'context'=>$measurement['context']],$measurement['rows']);
        $editor=(new T27Editor())->render($profile,$measurement,$zones);
        preg_match('/<script id="overlay-data" type="application\/json">(.*?)<\/script>/s',$editor['html'],$match);
        $data=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
        self::assertSame(160,$data['comparisonProfile']['valuation']['soldier']);
        self::assertSame(2502,$data['rows'][0]['army']['soldier']);
        $duel=(new DuelService())->simulate(['profile'=>$profile,'armies'=>['A'=>['soldier'=>2502],'B'=>['soldier'=>2502]],'seed'=>\Waar\MicroCombat\Experiment\ExperimentRunner::deriveSeed(42,'soldier-vs-soldier',0)]);
        self::assertSame($duel['directions'][0]['result']['attacker']['dead']['soldier']/2502,$measurement['rows'][0]['rawLossRatio']);
        $search=(new BoundedProfileSearch())->search($profile,$zones,'neutral',314159,2,1);
        foreach($search['candidates'] as $candidate) self::assertSame(160,$candidate['profile']['units']['soldier']['cost']);
        $profile['units']['soldier']['cost']=80;
        $this->expectException(\InvalidArgumentException::class);
        (new ConsequenceObjectives())->validate($profile,$zones,'neutral',42,1);
    }

    public function testCostsMustBePositiveBoundedIntegers(): void
    {
        foreach([0,-1,1.5,'80',null,400401] as $cost){
            $profile=EngineProfile::defaults();$profile['units']['soldier']['cost']=$cost;
            self::assertContains('units.soldier.cost',array_column(EngineProfile::validate($profile),'path'));
        }
        foreach([1,400400] as $cost){
            $profile=EngineProfile::defaults();$profile['units']['soldier']['cost']=$cost;
            self::assertSame([],EngineProfile::validate($profile));
        }
    }
}
