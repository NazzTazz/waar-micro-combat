<?php

use App\Game\Random\AddressedRandom;
use PHPUnit\Framework\TestCase;

final class AddressedRandomTest extends TestCase
{
    public function testNestedSuccessCountsAndEventIsolation(): void
    {
        foreach ([0,1,2,59,60,64,65,1000,1000000,32000000,4294967295] as $n) {
            foreach ([0,1,42,8191,2147483647] as $seed) {
                $random = new AddressedRandom($seed, 'A', 1);
                $previous = 0;
                foreach ([0,.000001,.01,.25,.3,.5,.9,.999999,1] as $p) {
                    $actual = $random->binomial($n, $p, 'spearman', 'hit/0/archer');
                    self::assertGreaterThanOrEqual($previous, $actual, "n=$n p=$p seed=$seed");
                    self::assertLessThanOrEqual($n, $actual);
                    $random->binomial(1000000,.7,'archer','impact/0/soldier/15000000');
                    self::assertSame($actual,$random->binomial($n,$p,'spearman','hit/0/archer'));
                    $previous = $actual;
                }
                self::assertSame($n,$previous);
            }
        }
    }

    public function testAnalyticMomentsAndSmallProbabilityMassesOnFixedSeeds(): void
    {
        // Tolerances fixed before execution, 7 standard errors, no seed selection.
        $samples=4096;
        foreach ([[1,.25],[2,.3],[10,.01],[59,.5],[60,.5],[64,.25],[65,.25],[1000,.01],[1000000,.3],[32000000,.99]] as [$n,$p]) {
            $mean=$n*$p;$variance=$mean*(1-$p);$sum=$squares=0.0;$histogram=[];
            for($seed=0;$seed<$samples;++$seed){
                $x=(new AddressedRandom($seed,'A',1))->binomial($n,$p,'soldier','hit/0/archer');
                $sum+=$x;$squares+=($x-$mean)**2;$histogram[$x]=($histogram[$x]??0)+1;
            }
            $fourth=3*$variance*$variance+$variance*(1-6*$p*(1-$p));
            self::assertEqualsWithDelta($mean,$sum/$samples,7*sqrt($variance/$samples),"mean n=$n p=$p");
            self::assertEqualsWithDelta($variance,$squares/$samples,7*sqrt(($fourth-$variance*$variance)/$samples),"variance n=$n p=$p");
            if($n<=10){
                $mass=(1-$p)**$n;
                for($k=0;$k<=$n;++$k){
                    self::assertEqualsWithDelta($samples*$mass,$histogram[$k]??0,7*sqrt($samples*$mass*(1-$mass))+1,"mass n=$n k=$k");
                    if($k<$n)$mass*=($n-$k)/($k+1)*$p/(1-$p);
                }
            }
        }
    }

    public function testDistinctDomainsAreNotOneSharedRandomVariable(): void
    {
        $products=$sumA=$sumB=0.0;$samples=4096;
        for($seed=0;$seed<$samples;++$seed){
            $a=(new AddressedRandom($seed,'A',2))->binomial(50,.3,'archer','hit/0/soldier');
            $b=(new AddressedRandom($seed,'B',2))->binomial(50,.3,'archer','hit/0/soldier');
            $sumA+=$a;$sumB+=$b;$products+=($a-15)*($b-15);
        }
        self::assertEqualsWithDelta(0,$products/$samples,7*10.5/sqrt($samples));
        self::assertNotSame($sumA,$sumB);
    }

    public function testSharedProtocolVectors(): void
    {
        $vectors=json_decode(file_get_contents(__DIR__.'/fixtures/addressed-vectors.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($vectors as $v){$r=new AddressedRandom($v['seed'],$v['army'],$v['round']);
            self::assertSame($v['sample'],$r->binomial($v['n'],$v['p'],$v['type'],$v['usage']));
            self::assertSame($v['integer'],$r->integer(12345,987654,$v['type'],'accuracy'));
        }
    }
}
