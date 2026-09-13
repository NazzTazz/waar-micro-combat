<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\EngineProfileMigrator;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;

require_once dirname(__DIR__).'/autoload.php';

final class CohortWorkshopIntegrationTest extends TestCase
{
    public function testLegacyMigrationIsExplicitAndPreservesUserChoices():void
    {
        $legacy=EngineProfile::defaults();$legacy['schemaVersion']=EngineProfile::LEGACY_SCHEMA_VERSION;
        foreach($legacy['units']as&$unit){unset($unit['baseAccuracy'],$unit['accuracySpread'],$unit['strikesPerAttack']);}unset($unit);
        $legacy['weather']=array_intersect_key($legacy['weather'],array_flip(['neutral','rain','snow','heat']));
        foreach($legacy['weather']as&$row)foreach($row as&$cell)$cell=$cell['attack'];unset($row,$cell);
        $legacy['units']['archer']['attack']='61';$legacy['units']['archer']['cost']=71;$legacy['weather']['rain']['archer']='0.7';
        $legacy['combat']=['maxRounds'=>7,'randomSpread'=>'0.1','tieBreakPolicy'=>'draw','lossCompressionPercent'=>8,'capturePercent'=>3];
        $result=(new EngineProfileMigrator())->migrate($legacy);$profile=$result['profile'];
        self::assertTrue($result['migration']['performed']);self::assertTrue($result['migration']['measurementsObsolete']);
        self::assertSame('61',$profile['units']['archer']['attack']);self::assertSame(71,$profile['units']['archer']['cost']);
        self::assertSame('0.15',$profile['units']['archer']['baseAccuracy']);self::assertSame('0',$profile['units']['archer']['accuracySpread']);self::assertSame(1,$profile['units']['archer']['strikesPerAttack']);
        self::assertSame('0.7',$profile['weather']['rain']['archer']['attack']);self::assertSame('1',$profile['weather']['rain']['archer']['baseAccuracy']);
        self::assertSame('draw',$profile['combat']['equalityPolicy']);self::assertContains('combat.randomSpread',$result['migration']['obsoleteFields']);
        self::assertContains('unsupported_schema',array_column(EngineProfile::validate($legacy),'code'));
    }

    public function testNativeDuelUsesDistinctCampModifiersAndExposesReplayableCohortReport():void
    {
        $profile=EngineProfile::defaults();$profile['weather']['wind']['archer']['baseAccuracy']='0.8';
        $modifier=['source'=>'training','id'=>'drill','label'=>'Entraînement','unitType'=>'archer','parameter'=>'baseAccuracy','operation'=>'multiply','value'=>'1.2'];
        $result=(new DuelService())->simulate(['requestId'=>'native','profile'=>$profile,'armies'=>['A'=>['archer'=>100],'B'=>['soldier'=>200]],'weather'=>['A'=>'wind','B'=>'neutral'],'modifiers'=>['A'=>[$modifier],'B'=>[]],'seed'=>42]);
        self::assertSame('waar-cohort-v2',$result['modelVersion']);self::assertSame('rust',$result['runtime']['kind']);self::assertCount(2,$result['directions']);
        $direction=$result['directions'][0];self::assertSame('waar-combat-result/2',$direction['result']['schemaVersion']);self::assertNotEmpty($direction['result']['replayHash']);
        $archer=array_values(array_filter($direction['result']['snapshot']['prepared']['attacker']['units'],static fn(array $unit):bool=>$unit['type']==='archer'))[0];
        self::assertSame('0.144',$archer['baseAccuracy']);self::assertSame(['training','weather'],array_column($archer['effects'],'source'));
        self::assertArrayHasKey('matrix',$direction['result']['rounds'][0]['attackerAction']);self::assertCount(4,$direction['result']['rounds'][0]['attackerAction']['matrix']);
        self::assertSame('wounded-capture-then-compress/1',$direction['consequences']['policyVersion']);
    }

    public function testPhpDiagnosticRuntimeUsesTheSameVersionedBoundary():void
    {
        $profile=EngineProfile::fromArray(EngineProfile::defaults());$request=(new CohortRequestFactory())->combat($profile,['soldier'=>10],['archer'=>5],42,'neutral','neutral');
        $runtime=new ProcessCohortRuntime(null,'php');$result=$runtime->resolve($request);
        self::assertSame('php',$runtime->provenance()['kind']);self::assertSame('waar-combat-result/2',$result['result']['schemaVersion']);self::assertSame('waar-cohort-v2',$result['result']['modelVersion']);
        $php=(new MonotypeMeasurementService($runtime))->measure($profile->toArray(),'neutral',42,1);$rust=(new MonotypeMeasurementService())->measure($profile->toArray(),'neutral',42,1);
        self::assertSame($rust['rows'],$php['rows']);self::assertSame('php',$php['context']['runtime']['kind']);
    }
}
