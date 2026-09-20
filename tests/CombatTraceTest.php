<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

require_once dirname(__DIR__).'/autoload.php';

final class CombatTraceTest extends TestCase
{
    public function testNativeTraceExplainsExactDamageWithoutChangingResolution():void
    {
        $values=EngineProfile::defaults();$values['units']['soldier']['baseAccuracy']='1';$values['units']['soldier']['accuracySpread']='0';$values['units']['spearman']['baseAccuracy']='1';$values['units']['spearman']['accuracySpread']='0';$values['units']['spearman']['defendingEfficiency']='1.5';$values['weather']['rain']['soldier']['attack']='0.5';$values['relations']=[['acting'=>'soldier','target'=>'spearman','factor'=>'2'],['acting'=>'spearman','target'=>'soldier','factor'=>'0.5']];
        $profile=EngineProfile::fromArray($values);$factory=new CohortRequestFactory();$runtime=new ProcessCohortRuntime();
        $request=$factory->combat($profile,['soldier'=>10],['spearman'=>10],42,'rain','rain');$full=$runtime->resolve($request);$request['traceLevel']='none';$none=$runtime->resolve($request);
        foreach(['winner','reason','decision','attacker','defender']as$key)self::assertSame($full['result'][$key],$none['result'][$key]);
        $attack=$full['result']['rounds'][0]['attackerAction']['matrix']['soldier']['spearman'];
        self::assertSame(10,$attack['allocatedAttempts']);self::assertSame(10,$attack['appliedHits']);self::assertSame('3.5',$attack['attackPerStrike']);self::assertSame('1',$attack['defendingEfficiency']);self::assertSame('2',$attack['attackFactor']);self::assertSame('7',$attack['damagePerHit']);self::assertSame('70',$attack['damageEmitted']);
        $reply=$full['result']['rounds'][0]['defenderAction']['matrix']['spearman']['soldier'];
        self::assertSame('1.5',$reply['defendingEfficiency']);self::assertSame('0.5',$reply['attackFactor']);self::assertSame('6',$reply['damagePerHit']);self::assertSame('60',$reply['damageEmitted']);
    }

    public function testRandomnessSimultaneityAndEarlyExtinctionStayDeterministic():void
    {
        $values=EngineProfile::defaults();$values['units']['archer']['attack']='100';$values['units']['archer']['baseAccuracy']='1';$values['units']['archer']['accuracySpread']='0';$values['units']['soldier']['baseAccuracy']='1';$values['units']['soldier']['accuracySpread']='0';$profile=EngineProfile::fromArray($values);$factory=new CohortRequestFactory();$runtime=new ProcessCohortRuntime();
        foreach([0,42,2147483647]as$seed){$request=$factory->combat($profile,['archer'=>100],['soldier'=>1],$seed,'neutral','neutral');$first=$runtime->resolve($request);$second=$runtime->resolve($request);self::assertSame($first,$second);self::assertCount(1,$first['result']['rounds']);self::assertSame(1,$first['result']['rounds'][0]['defenderAction']['attempts'],'Une unité éliminée pendant le round riposte depuis son état de début de round.');}
    }

    public function testDuelHasIndependentReportsWithReversedLabels():void
    {
        $result=(new DuelService())->simulate(['profile'=>EngineProfile::defaults(),'armies'=>['A'=>['soldier'=>5],'B'=>['archer'=>8]],'weather'=>['A'=>'neutral','B'=>'neutral'],'seed'=>42]);
        foreach($result['directions']as$direction){self::assertSame('waar-combat-result/2',$direction['result']['schemaVersion']);$side=$direction['labels']['attacker']==='A'?'attacker':'defender';self::assertSame(5,$direction['result']['initialArmies'][$side]['soldier']);self::assertNotEmpty($direction['result']['replayHash']);}
    }
}
