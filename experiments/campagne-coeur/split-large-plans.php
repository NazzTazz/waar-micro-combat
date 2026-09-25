<?php
declare(strict_types=1);

/* Deterministic configuration partitioning. No runtime or combat calls. */
use Waar\MicroCombat\Exploration\ParametricCampaign;

require dirname(__DIR__, 2).'/autoload.php';
$source = __DIR__;
$target = $source.'/segments';
$names = [
    'M-combat-woundDamageThreshold',
    'M-combat-surrender',
    'M-combat-maxRounds',
    'M-combat-lossCompressionPercent',
    'X-simplex',
];
if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) throw new RuntimeException('Dossier segments inaccessible.');
$rows = [];
foreach ($names as $name) {
    $loaded = ParametricCampaign::load($source.'/'.$name.'.json');
    $groups = [];
    foreach ($loaded['plan']['scenarios'] as $scenario) {
        if (!preg_match('/-b(12000|120000|360000)(?:-|$)/', $scenario['id'], $match)) {
            throw new RuntimeException("Budget introuvable : {$name}/{$scenario['id']}");
        }
        $group = 'b'.$match[1];
        if ($name === 'X-simplex') {
            if (!preg_match('/-vs-(soldier|spearman|archer|knight|balanced)$/', $scenario['id'], $opponent)) {
                throw new RuntimeException("Adversaire introuvable : {$scenario['id']}");
            }
            $group .= '-vs-'.$opponent[1];
        }
        $groups[$group][] = $scenario;
    }
    ksort($groups);
    $sourcePreview = ParametricCampaign::preview($loaded['plan'], $loaded['profile']);
    $sum = 0;
    foreach ($groups as $group=>$scenarios) {
        $stem = $name.'-'.$group;
        $plan = $loaded['plan'];
        $plan['profile'] = '../../../reports/campaign-manual-sol/profile.json';
        $plan['output'] = '../../../reports/campagne-coeur/segments/'.$stem;
        $plan['scenarios'] = $scenarios;
        $preview = ParametricCampaign::preview($plan, $loaded['profile']);
        $plan['limits']['maxCombats'] = $preview['combats'];
        $path = $target.'/'.$stem.'.json';
        file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
        $verified = ParametricCampaign::load($path);
        if (ParametricCampaign::preview($verified['plan'], $verified['profile'])['combats'] !== $preview['combats']) {
            throw new RuntimeException("Prévisualisation discordante : {$stem}");
        }
        $sum += $preview['combats'];
        $rows[] = ['source'=>$name.'.json', 'segment'=>basename($path), 'scenarioCount'=>count($scenarios),
            'configurations'=>$preview['experiments'], 'directions'=>$preview['directions'],
            'combats'=>$preview['combats'], 'maxCombats'=>$plan['limits']['maxCombats'], 'sha256'=>hash_file('sha256', $path)];
    }
    if ($sum !== $sourcePreview['combats']) throw new RuntimeException("Le total des segments diffère de {$name}.");
}
$manifest = ['schemaVersion'=>'waar-campaign-segments/1', 'status'=>'previewed', 'combatsExecuted'=>0,
    'sourcePlans'=>$names, 'segments'=>count($rows), 'combats'=>array_sum(array_column($rows, 'combats')),
    'rows'=>$rows];
file_put_contents($target.'/segments-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['segments'=>$manifest['segments'], 'combats'=>$manifest['combats'],
    'maximumSegmentCombats'=>max(array_column($rows, 'combats'))], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), "\n";
