<?php

require dirname(__DIR__).'/autoload.php';
use Waar\MicroCombat\Workshop\{EngineProfile,MonotypeMeasurementService,ConsequenceObjectives,T27Editor};

$p = json_decode(file_get_contents(__DIR__.'/modele-cohortes-c1.json'), true, 512, JSON_THROW_ON_ERROR);
$m = (new MonotypeMeasurementService())->measure($p, 'neutral', 42, 100);
$wins = ['soldier' => ['soldier' => .5, 'spearman' => .7, 'archer' => .3, 'knight' => .7],
 'spearman' => ['soldier' => .3, 'spearman' => .05, 'archer' => .3, 'knight' => .05],
 'archer' => ['soldier' => .7, 'spearman' => .7, 'archer' => .7, 'knight' => .05],
 'knight' => ['soldier' => .3, 'spearman' => .05, 'archer' => .95, 'knight' => .5]];
$zones = [];
foreach ($m['rows'] as $r) {
    $a = $r['attackerType'];
    $d = $r['defenderType'];
    $x = $wins[$a][$d];
    if ($r['side'] === 'defender') {
        $x = 1 - $x;
    }
    $mirror = $a === $d;
    $rx = ($x == .5 || $x <= .05 || $x >= .95) ? .05 : .1;
    $y = .5;
    $ry = 1.0;
    if ($mirror && $a === 'soldier') {
        $y = 1.0;
        $ry = .05;
    } elseif ($mirror) {
        $y = .1;
        $ry = $a === 'archer' ? .1 : .05;
    }
    $zones[] = ['id' => $r['id'], 'scenarioId' => $r['scenarioId'], 'side' => $r['side'], 'center' => ['x' => $x, 'y' => $y], 'radii' => ['x' => $rx, 'y' => $ry],
     'shape' => 'ellipse', 'enabled' => true, 'approval' => 'draft', 'sourceFingerprint' => $m['profileFingerprint'], 'modelVersion' => $m['modelVersion'], 'context' => $m['context']];
}
(new ConsequenceObjectives())->validate($p, $zones, 'neutral', 42, 100);
$editor = (new T27Editor())->render($p, $m, $zones);
preg_match('/<script id="overlay-data" type="application\/json">(.*?)<\/script>/s', $editor['html'], $match);
$data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
foreach ($data['zonesDocument']['zones'] as $i => &$zone) {
    $zone['source']['originalCenter'] = ['x' => $m['rows'][$i]['winRate'], 'y' => $m['rows'][$i]['rawCasualtyRatio']];
    $zone['source']['modifiedManually'] = true;
}
unset($zone);
$evaluator = new Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator();
$summary = [];
foreach ($m['rows'] as $i => $row) {
    $summary[] = ['id' => $row['id'], 'winRate' => $row['winRate'], 'lossRatio' => $row['rawCasualtyRatio'], 'accepted' => $evaluator->evaluate($row['winRate'], $row['rawCasualtyRatio'], $zones[$i]) === 'inside'];
}
echo json_encode(['measurement' => $m, 'editorZones' => $data['zonesDocument'], 'apiZones' => ['schemaVersion' => 'waar-consequence-acceptance-zones/0.1', 'profileFingerprint' => $m['profileFingerprint'], 'context' => $m['context'], 'zones' => $zones], 'summary' => $summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
