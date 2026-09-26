<?php

declare(strict_types=1);

use Waar\MicroCombat\Exploration\ParametricCampaign;

require dirname(__DIR__, 2).'/autoload.php';

$root = __DIR__;
$inventory = json_decode((string) file_get_contents($root.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
$total = ['plans' => 0, 'experiments' => 0, 'directions' => 0, 'combats' => 0];
$rows = [];
foreach ($inventory['plans'] as $entry) {
    $path = $root.'/'.$entry['plan'];
    if (hash_file('sha256', $path) !== $entry['planSha256']) {
        throw new RuntimeException('Empreinte du plan modifiée : '.$entry['plan']);
    }
    $loaded = ParametricCampaign::load($path);
    $preview = ParametricCampaign::preview($loaded['plan'], $loaded['profile']);
    ++$total['plans'];
    foreach (['experiments', 'directions', 'combats'] as $key) {
        $total[$key] += $preview[$key];
    }
    $rows[] = ['plan' => $entry['plan'], 'phase' => $entry['phase'], 'combats' => $preview['combats']];
}
echo json_encode(['schemaVersion' => 'waar-optimized-campaign-preview/1', 'total' => $total, 'plans' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
