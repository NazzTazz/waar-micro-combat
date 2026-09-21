<?php

// Local acceptance fixture only; never points implicitly at the shared store.
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\SharedProfiles;

require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__).'/engines/waar-cohort/autoload.php';

try {
    $directory = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || count($argv) !== 2 || trim($directory) === '' || file_exists($directory) || is_link($directory)) {
        throw new InvalidArgumentException('Usage : php bin/prepare-rc1-review.php <nouveau-repertoire-isole>. Le répertoire doit être inexistant.');
    }
    $fixture = require dirname(__DIR__).'/engines/waar-cohort/tests/fixtures/rc1.php';
    $request = $fixture(['soldier'=>3, 'spearman'=>1, 'knight'=>18], ['soldier'=>1000]);
    $ruleset = $request['ruleset'];
    $profile = EngineProfile::defaults();
    foreach ($ruleset['units'] as $unit) {
        $type = $unit['type'];
        unset($unit['type']);
        $profile['units'][$type] = $unit;
    }
    $profile['relations'] = [];
    foreach ($ruleset['engagements'] as $acting=>$row) foreach ($row as $target=>$cell) {
        if ($cell['attackFactor'] !== '1') $profile['relations'][] = ['acting'=>$acting, 'target'=>$target, 'factor'=>$cell['attackFactor']];
    }
    $profile['combat'] = [
        'maxRounds'=>$ruleset['maxRounds'],
        'surrenderEnabled'=>$ruleset['surrender']['enabled'],
        'surrenderDeadPercent'=>(int)round((float)$ruleset['surrender']['deadRatio'] * 100),
        'tieBreakCriterion'=>$ruleset['tieBreak']['criterion'],
        'equalityPolicy'=>$ruleset['tieBreak']['equality'],
        'lossCompressionPercent'=>$request['consequences']['compressionPercent'],
        'capturePercent'=>$request['consequences']['capturePercent'],
    ];
    $saved = (new SharedProfiles($directory))->save('RC-1 — recette isolée', $profile);
    echo json_encode(['directory'=>realpath($directory), 'id'=>$saved['id'], 'name'=>$saved['name']], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
