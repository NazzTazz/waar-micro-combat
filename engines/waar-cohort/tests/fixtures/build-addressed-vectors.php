<?php
// Emit shared protocol vectors; the statistical tests independently check the law.
require dirname(__DIR__,2).'/autoload.php';
$vectors=[];
foreach([0,1,42,2147483647] as $seed)foreach([0,1,59,60,64,65,1000000,32000000,4294967295] as $n)foreach([.000001,.25,.3,.5,.999999] as $p){
    $army=$seed%2===0?'A':'B';$round=2;$type='archer';$usage='hit/0/spearman';
    $r=new App\Game\Random\AddressedRandom($seed,$army,$round);
    $vectors[]=[...compact('seed','army','round','n','p','type','usage'),'sample'=>$r->binomial($n,$p,$type,$usage),'integer'=>$r->integer(12345,987654,$type,'accuracy')];
}
echo json_encode($vectors,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
