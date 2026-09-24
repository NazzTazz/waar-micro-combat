<?php

require dirname(__DIR__).'/autoload.php';
$p = json_decode(file_get_contents(__DIR__.'/gameplay-narre-v2-legacy.json'), true, 512, JSON_THROW_ON_ERROR);
$m = (new Waar\MicroCombat\Workshop\MonotypeMeasurementService())->measure($p, 'neutral', 42, 100);
$expected = ['archer-vs-soldier' => 'attacker', 'soldier-vs-archer' => 'defender', 'soldier-vs-knight' => 'attacker', 'knight-vs-soldier' => 'defender', 'knight-vs-spearman' => 'defender', 'spearman-vs-knight' => 'defender'];
foreach ($m['rows'] as $row) {
    if (($expected[$row['scenarioId']] ?? null) === $row['side'] && $row['winRate'] !== 1) {
        throw new RuntimeException('Expected 100/100 observed wins: '.$row['id']);
    }
    if ($row['id'] === 'spearman-vs-knight/attacker' && $row['rawLossRatio'] < 0.9) {
        throw new RuntimeException('Attacking spearmen were not decimated.');
    }
}
echo json_encode($m, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
