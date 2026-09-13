<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\BattleResult;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\SideOutcome;
use Waar\MicroCombat\UnitOutcome;
use Waar\MicroCombat\UnitType;
use Waar\MicroCombat\Workshop\BattleConsequences;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProfileValidationException;

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopCoreTest extends TestCase
{
    /** @return iterable<string,array{int,int,int}> */
    public static function compressionCases():iterable{yield 'zero'=>[100,0,0];yield 'eight-of-100'=>[100,8,8];yield 'eight-of-50'=>[50,8,4];yield 'eight-of-7'=>[7,8,0];yield 'full'=>[100,100,100];}

    #[DataProvider('compressionCases')]
    public function testCompressionIsFlooredPerType(int $rawLosses,int $percent,int $expected):void
    {
        $profile=EngineProfile::defaults();$profile['combat']['lossCompressionPercent']=$percent;
        $result=(new BattleConsequences())->apply($this->fixtureResult($rawLosses,CombatSide::Defender),EngineProfile::fromArray($profile));
        self::assertSame($expected,$result['consequences']['attacker']['units']['soldier']['appliedLosses']);
    }

    public function testCaptureUsesSurvivorsAfterCompressionAndOnlyDefeatedEligibleTypes():void
    {
        $profile=EngineProfile::defaults();$profile['combat']['lossCompressionPercent']=8;$profile['combat']['capturePercent']=10;$profile['units']['soldier']['capturable']=true;
        $raw=$this->fixtureResult(1000,CombatSide::Defender,1000);$before=$raw->toArray();$result=(new BattleConsequences())->apply($raw,EngineProfile::fromArray($profile));
        $soldier=$result['consequences']['attacker']['units']['soldier'];
        self::assertSame(['initial'=>1000,'rawLosses'=>1000,'economicLoss'=>13760,'appliedLosses'=>80,'survivorsBeforeCapture'=>920,'prisoners'=>92,'free'=>828,'capturable'=>true],$soldier);
        self::assertSame(13760,$result['consequences']['attacker']['totals']['economicLoss']);
        self::assertSame(0,$result['consequences']['defender']['units']['soldier']['prisoners']);
        self::assertSame(1000,$soldier['appliedLosses']+$soldier['prisoners']+$soldier['free']);
        self::assertSame($before,$raw->toArray());
    }

    public function testDrawNeverCapturesAndZeroCompressionStillCanCaptureOnDefeat():void
    {
        $profile=EngineProfile::defaults();$profile['combat']['lossCompressionPercent']=0;$profile['combat']['capturePercent']=10;$profile['units']['soldier']['capturable']=true;$p=EngineProfile::fromArray($profile);
        self::assertSame(100,(new BattleConsequences())->apply($this->fixtureResult(100,null),$p)['consequences']['attacker']['units']['soldier']['free']);
        self::assertSame(10,(new BattleConsequences())->apply($this->fixtureResult(100,CombatSide::Defender),$p)['consequences']['attacker']['units']['soldier']['prisoners']);
    }

    public function testNeutralWeatherIsBitExactAndWeatherOnlyChangesPreparedAttack():void
    {
        $values=EngineProfile::defaults();$values['weather']['rain']['soldier']['attack']='0.5';$values['weather']['rain']['soldier']['baseAccuracy']='0.8';$profile=EngineProfile::fromArray($values);$factory=new CohortRequestFactory();
        $request=$factory->combat($profile,['soldier'=>10],['soldier'=>10],42,'neutral','rain');
        self::assertSame([],$request['attacker']['modifiers']);
        self::assertSame(['attack','baseAccuracy'],array_column($request['defender']['modifiers'],'parameter'));
        self::assertSame(['0.5','0.8'],array_column($request['defender']['modifiers'],'value'));
        self::assertNotContains('accuracySpread',array_column($request['defender']['modifiers'],'parameter'));
        $duel=['requestId'=>'1','profile'=>$profile->toArray(),'armies'=>['A'=>['soldier'=>100],'B'=>['soldier'=>100]],'weather'=>['A'=>'neutral','B'=>'rain'],'seed'=>42];
        self::assertSame((new DuelService())->simulate($duel),(new DuelService())->simulate($duel));
    }

    public function testDuelPreservesLabelsCostsAndAllowsUnusedMissingProfiles():void
    {
        $profile=EngineProfile::defaults();$profile['units']['archer']=null;$profile['units']['knight']=null;$profile['units']['spearman']=null;
        $result=(new DuelService())->simulate(['requestId'=>'x','profile'=>$profile,'armies'=>['A'=>['soldier'=>100],'B'=>['soldier'=>120]],'weather'=>'neutral','seed'=>42]);
        self::assertSame(['A'=>8000,'B'=>9600],$result['costs']);self::assertSame('A',$result['directions'][0]['labels']['attacker']);self::assertSame('B',$result['directions'][1]['labels']['attacker']);self::assertSame('x',$result['requestId']);
    }

    public function testDuelReportsEveryIdentifiableError():void
    {
        $profile=EngineProfile::defaults();$profile['units']['archer']=null;
        try{(new DuelService())->simulate(['profile'=>$profile,'armies'=>['A'=>['archer'=>1],'B'=>[]],'weather'=>['A'=>'unknown','B'=>'neutral'],'seed'=>-1]);self::fail();}catch(ProfileValidationException $e){$codes=array_column($e->errors,'code');self::assertContains('missing_unit',$codes);self::assertContains('empty_army',$codes);self::assertContains('unknown_weather',$codes);self::assertContains('invalid_seed',$codes);}
    }

    public function testProfileRejectsDuplicateUnknownDiagonalAndBadBounds():void
    {
        $profile=EngineProfile::defaults();$profile['relations']=[['acting'=>'soldier','target'=>'soldier','factor'=>'1'],['acting'=>'x','target'=>'knight','factor'=>'20'],['acting'=>'archer','target'=>'knight','factor'=>'2'],['acting'=>'archer','target'=>'knight','factor'=>'2']];$profile['combat']['capturePercent']=11;
        $errors=EngineProfile::validate($profile);$codes=array_column($errors,'code');self::assertContains('diagonal_relation',$codes);self::assertContains('unknown_unit',$codes);self::assertContains('duplicate_relation',$codes);self::assertContains('out_of_range',$codes);
    }

    public function testFingerprintIsSemanticAndDeterministic():void
    {
        $a=EngineProfile::defaults();$a['relations']=[['acting'=>'knight','target'=>'archer','factor'=>'1.5'],['acting'=>'archer','target'=>'spearman','factor'=>'2']];$b=$a;$b['relations']=array_reverse($b['relations']);
        self::assertSame(EngineProfile::fromArray($a)->semanticFingerprint(),EngineProfile::fromArray($b)->semanticFingerprint());
    }

    public function testProfileRejectsUnknownNestedFields():void
    {
        $profile=EngineProfile::defaults();$profile['units']['soldier']['magic']=1;$profile['relations'][]=['acting'=>'soldier','target'=>'archer','factor'=>'1.2','inverse'=>true];$profile['weather']['rain']['dragon']=['attack'=>'1','baseAccuracy'=>'1'];$profile['weather']['rain']['soldier']['accuracySpread']='1';$profile['combat']['initiative']=2;
        $paths=array_column(EngineProfile::validate($profile),'path');
        foreach(['units.soldier.magic','relations.0.inverse','weather.rain.dragon','weather.rain.soldier.accuracySpread','combat.initiative'] as $path)self::assertContains($path,$paths);
    }

    public function testMalformedRelationReturnsErrorsInsteadOfCrashing():void
    {
        $profile=EngineProfile::defaults();$profile['relations']=[['acting'=>['soldier'],'target'=>null,'factor'=>[]]];
        $errors=EngineProfile::validate($profile);
        self::assertContains('unknown_unit',array_column($errors,'code'));
        self::assertContains('invalid_decimal',array_column($errors,'code'));
    }

    private function fixtureResult(int $soldierLosses,?CombatSide $winner,int $initial=100):BattleResult
    {
        $side=function(int $losses)use($initial):SideOutcome{$units=[];foreach(UnitType::cases() as $type){$n=$type===UnitType::Soldier?$initial:0;$dead=$type===UnitType::Soldier?$losses:0;$units[]=new UnitOutcome($type,$n,$n-$dead,$dead,$n*1000000,($n-$dead)*1000000,1);}return new SideOutcome($units);};
        return new BattleResult($winner,'test',3,$side($soldierLosses),$side(0),[],'test','1',42);
    }
}
