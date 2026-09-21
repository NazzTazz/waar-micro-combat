<?php

use App\Game\Combat\ConsequencePolicy;
use App\Game\Random\ConsequenceSampler;
use PHPUnit\Framework\TestCase;

final class ConsequenceSamplerTest extends TestCase
{
    public function testSharedVectorsAndStreamConsumption(): void
    {
        $vectors=json_decode(file_get_contents(__DIR__.'/fixtures/consequence-v3-vectors.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame(ConsequenceSampler::VERSION,$vectors['protocol']);
        foreach($vectors['samples'] as $row){
            $sampler=new ConsequenceSampler($row['seed'],$row['side'],$row['type'],$row['stage']);
            self::assertSame($row['sample'],$sampler->binomial($row['n'],$row['percent']),json_encode($row));
            self::assertSame($row['nextBits'],(int)floor($sampler->uniform()*4503599627370496));
        }
    }

    public function testProjectionPartitionBoundsAndExtremes(): void
    {
        foreach([0,1,10,18,19,20,23,32,64,65,100000,4294967295] as $initial)
        foreach([0,1,5,100] as $compression) foreach([0,24,50] as $capture)
        foreach([false,true] as $eligible) foreach([0,42,2147483647] as $seed){
            $dead=intdiv($initial,3);$wounded=$initial-$dead;
            [$h,$w,$d,$p,$selected]=ConsequencePolicy::counts($initial,$dead,$wounded,$eligible,$compression,$capture,$seed,'attacker','soldier',ConsequencePolicy::PROBABILISTIC_VERSION);
            self::assertSame($initial,$h+$w+$d+$p);
            self::assertGreaterThanOrEqual(0,min($h,$w,$d,$p,$selected));
            self::assertLessThanOrEqual($dead,$d);
            self::assertLessThanOrEqual($wounded,$w+$p);
            self::assertLessThanOrEqual($selected,$p);
            self::assertLessThanOrEqual($wounded,$selected);
            if(!$eligible||$capture===0)self::assertSame(0,$selected);
            if($compression===0)self::assertSame([$initial,0,0,0],[$h,$w,$d,$p]);
            if($compression===100){self::assertSame($dead,$d);self::assertSame($wounded,$w+$p);self::assertSame($selected,$p);}
            // Capture diagnosis is independent of the compression rate, including k=0.
            self::assertSame($selected,ConsequencePolicy::counts($initial,$dead,$wounded,$eligible,0,$capture,$seed,'attacker','soldier',ConsequencePolicy::PROBABILISTIC_VERSION)[4]);
        }
    }

    public function testBinomialLawOnFixedSeeds(): void
    {
        // Fixed list, no retry: 4096 seeds. Means: Bernstein bound, failure <= 1e-6.
        // Second moment: six theoretical standard errors using the binomial fourth moment.
        foreach([[1,5],[10,5],[18,5],[19,5],[20,5],[23,5],[64,1],[65,1],[65,50],[100000,1],[100000,50],[4294967295,1],[4294967295,50]] as [$n,$percent]){
            $p=$percent/100;$mean=$n*$p;$variance=$mean*(1-$p);$sum=$squares=$zeros=0;
            for($seed=0;$seed<4096;$seed++){
                $x=(new ConsequenceSampler($seed,'attacker','soldier','dead'))->binomial($n,$percent);
                $sum+=$x;$squares+=($x-$mean)**2;$zeros+=(int)($x===0);
            }
            self::assertEqualsWithDelta($mean,$sum/4096,self::meanMargin($variance,1),"mean n=$n p=$p");
            $fourth=3*$variance*$variance+$variance*(1-6*$p*(1-$p));
            self::assertEqualsWithDelta($variance,$squares/4096,6*sqrt(($fourth-$variance*$variance)/4096),"variance n=$n p=$p");
            if($n<=65){$zero=(1-$p)**$n;self::assertEqualsWithDelta($zero,$zeros/4096,self::meanMargin($zero*(1-$zero),1),"zero n=$n p=$p");}
        }
    }

    public function testCaptureLawAndFragmentation(): void
    {
        $sums=[0,0,0];$hist=array_fill(0,3,array_fill(0,9,0));$captured=$free=$selected=$archerDead=$archerWounded=0;
        for($seed=0;$seed<4096;$seed++){
            $project=static fn($n,$d,$w,$eligible,$s,$type)=>ConsequencePolicy::counts($n,$d,$w,$eligible,5,24,$s,'defender',$type,ConsequencePolicy::PROBABILISTIC_VERSION);
            $one=$project(68,0,68,true,$seed,'soldier');$captured+=$one[3];$free+=$one[1];$selected+=$one[4];
            $archer=$project(32,9,14,false,$seed,'archer');$archerDead+=$archer[2];$archerWounded+=$archer[1];
            $totals=[$one[1]+$one[2]+$one[3],0,0];
            foreach(['soldier'=>18,'spearman'=>19,'archer'=>14,'knight'=>17] as $type=>$n){$part=$project($n,intdiv($n,2),$n-intdiv($n,2),false,$seed,$type);$totals[1]+=$part[1]+$part[2]+$part[3];}
            foreach([2*$seed,2*$seed+1] as $combatSeed){$part=$project(34,0,34,true,$combatSeed,'soldier');$totals[2]+=$part[1]+$part[2]+$part[3];}
            foreach($totals as $i=>$total){$sums[$i]+=$total;$hist[$i][min(8,$total)]++;}
        }
        foreach([[$captured,.012],[$free,.038],[$selected,.24]] as [$sum,$p])self::assertEqualsWithDelta(68*$p,$sum/4096,self::meanMargin(68*$p*(1-$p),1));
        foreach([[$archerDead,9],[$archerWounded,14]] as [$sum,$n])self::assertEqualsWithDelta($n*.05,$sum/4096,self::meanMargin($n*.05*.95,1));
        $prob=.95**68;$pmf=[];
        for($k=0;$k<8;$k++){$pmf[]=$prob;$prob*=((68-$k)/($k+1))*(.05/.95);}
        $pmf[]=1-array_sum($pmf);
        foreach($sums as $i=>$sum){
            self::assertEqualsWithDelta(3.4,$sum/4096,self::meanMargin(68*.05*.95,1));
            foreach($pmf as $k=>$p)self::assertEqualsWithDelta($p,$hist[$i][$k]/4096,self::meanMargin($p*(1-$p),1),"partition $i category $k");
        }
    }

    public function testProjectionCostAtSmallAndU32Populations(): void
    {
        // Bounded synthetic projections only, no physical combat or machine-dependent threshold.
        $timings=[];
        foreach([18,65,100000,4294967295] as $n)foreach([1,5,50,100] as $percent){
            $start=hrtime(true);$sum=0;
            for($seed=0;$seed<1000;$seed++){
                $out=ConsequencePolicy::counts($n,0,$n,false,$percent,24,$seed,'attacker','knight',ConsequencePolicy::PROBABILISTIC_VERSION);
                $sum+=$out[1];self::assertSame($n,array_sum(array_slice($out,0,4)));
            }
            $timings[]=['n'=>$n,'percent'=>$percent,'projections'=>1000,'milliseconds'=>(hrtime(true)-$start)/1e6,'mean'=>$sum/1000];
        }
        if(getenv('WAAR_CONSEQUENCE_TIMINGS'))fwrite(STDERR,json_encode($timings,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private static function meanMargin(float $variance,int $bound): float
    {
        $t=log(2000000);
        return sqrt(2*$variance*$t/4096)+2*$bound*$t/(3*4096);
    }
}
