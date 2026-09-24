<?php
declare(strict_types=1);

use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

require dirname(__DIR__, 2).'/autoload.php';

$profilePath = dirname(__DIR__, 2).'/reports/campaign-manual-sol/profile.json';
$output = dirname(__DIR__, 2).'/reports/campagne-coeur/D3-physical-thresholds/traces';
$source = file_get_contents($profilePath);
if ($source === false) {
    throw new RuntimeException('Profil de référence illisible.');
}
$base = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
$modifier = [
    'source' => 'diagnostic',
    'id' => 'accuracy-one',
    'label' => 'Précision exacte du diagnostic',
    'unitType' => 'soldier',
    'parameter' => 'baseAccuracy',
    'operation' => 'multiply',
    'value' => '10',
];
$factory = new CohortRequestFactory();
$runtime = new ProcessCohortRuntime();
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Création du dossier de traces impossible.');
}
foreach ([['4.999999', 1], ['5', 1], ['5.000001', 1], ['5.000001', 2]] as [$attack, $rounds]) {
    $data = $base;
    $data['units']['soldier']['attack'] = $attack;
    $data['combat']['maxRounds'] = $rounds;
    $profile = EngineProfile::fromArray($data);
    $request = $factory->combat($profile, ['soldier' => 1], ['soldier' => 1], 42, 'neutral', 'neutral', [$modifier], [$modifier], 'full', 'A', 'B');
    $response = $runtime->resolve($request);
    $document = [
        'sourcePlan' => 'D3-physical-thresholds.json',
        'profileSha256' => hash('sha256', $source),
        'binarySha256' => hash_file('sha256', dirname(__DIR__, 2).'/engines/waar-cohort/rust/target/release/waar-cohort-cli.exe'),
        'note' => 'Rejeu individuel diagnostique, seed 42 ; ne fait pas partie des 2000 répétitions batch.',
        'request' => $request,
        'response' => $response,
    ];
    $name = 'attack-'.str_replace('.', '_', $attack).'-rounds-'.$rounds.'.json';
    $target = $output.'/'.$name;
    $temporary = tempnam($output, '.trace-');
    if ($temporary === false) {
        throw new RuntimeException('Fichier temporaire impossible.');
    }
    file_put_contents($temporary, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    if (!rename($temporary, $target)) {
        throw new RuntimeException('Publication de la trace impossible.');
    }
    echo $target, "\n";
}
