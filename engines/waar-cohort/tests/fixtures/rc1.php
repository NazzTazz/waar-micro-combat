<?php

// Reconstructed from issue #12, not a mutation of a tester save or a replay of missing seeds.
return static function(array $attacker, array $defender, int $seed=42): array {
    $request=\App\Game\Combat\DemoRequestFactory::combat('full');
    $values=[
        'soldier'=>['8','25','0.10',1,10,'1',true],
        'spearman'=>['12','120','0.60',1,70,'2',false],
        'archer'=>['70','50','0.35',5,70,'0.75',false],
        'knight'=>['350','250','0.70',5,550,'1',false],
    ];
    foreach($request['ruleset']['units'] as &$unit){
        [$unit['attack'],$unit['structure'],$unit['baseAccuracy'],$unit['strikesPerAttack'],$unit['cost'],$unit['defendingEfficiency'],$unit['capturable']]=$values[$unit['type']];
        $unit['accuracySpread']='0';
    }unset($unit);
    foreach($request['ruleset']['engagements'] as $acting=>&$row)foreach($row as $target=>&$cell)$cell['attackFactor']=$acting==='spearman'&&$target==='knight'?'1.5':'1';unset($row,$cell);
    $request['ruleset']['maxRounds']=20;
    $request['ruleset']['surrender']=['enabled'=>true,'deadRatio'=>'0.35'];
    $request['ruleset']['tieBreak']=['criterion'=>'economic','equality'=>'defender'];
    $request['attacker']=['units'=>$attacker,'modifiers'=>[]];
    $request['defender']=['units'=>$defender,'modifiers'=>[]];
    $request['seed']=$seed;
    $request['consequences']=['compressionPercent'=>5,'capturePercent'=>24];
    return $request;
};
