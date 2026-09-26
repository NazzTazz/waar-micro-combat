<?php

/** Fixed, simulation-free inputs for issue #25. */
require dirname(__DIR__).'/autoload.php';

use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;

$root = dirname(__DIR__);
$profiles = [
    'nazz' => 'experiments/campagne-coeur/reference-profile.json',
    'test2' => 'resources/workshop-default-profile.json',
];
$factory = new CohortRequestFactory();
$document = [
    'schemaVersion' => 'waar-b1-corpus/1',
    'baseHead' => '05ebe6f3f2c29c9faf80e28a2558ed60c13e130f',
    'seed' => 42,
    'weather' => 'neutral',
    'profiles' => [],
    'cases' => [],
];
foreach ($profiles as $key => $path) {
    $raw = file_get_contents($root.'/'.$path);
    if ($raw === false) {
        throw new RuntimeException('Missing profile: '.$path);
    }
    $profile = EngineProfile::fromArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    $document['profiles'][$key] = [
        'path' => $path,
        'sha256' => hash('sha256', $raw),
        'id' => $profile->id,
        'label' => $profile->label,
        'semanticFingerprint' => $profile->semanticFingerprint(),
        'maxRounds' => $profile->maxRounds,
    ];
    $costs = $profile->costs();
    $mixed = static function (int $budget) use ($costs): array {
        $result = [];
        foreach ($costs as $type => $cost) {
            $result[$type] = intdiv(intdiv($budget, 4), $cost);
        }
        return $result;
    };
    $cases = [
        'archer-5-mirror' => [['archer' => 5], ['archer' => 5]],
        'spear-171-knight-21' => [['spearman' => 171], ['knight' => 21]],
        'soldier-12000-spear-1714' => [['soldier' => 12000], ['spearman' => 1714]],
        'mixed-120000' => [$mixed(120000), $mixed(120000)],
    ];
    if ($key === 'nazz') {
        $cases['mixed-scale-800000'] = [$mixed(800000), $mixed(800000)];
    }
    foreach ($cases as $name => [$armyA,$armyB]) {
        $scenarios = [];
        foreach ([['A', 'B'], ['B', 'A']] as [$attacker,$defender]) {
            $armies = ['A' => $armyA, 'B' => $armyB];
            $combat = $factory->combat($profile, $armies[$attacker], $armies[$defender], 42, 'neutral', 'neutral', [], [], 'none', $attacker, $defender);
            $scenarios[] = [
                'id' => $attacker.'-'.$defender,
                'seedKey' => 0,
                'armyIdentities' => $combat['armyIdentities'],
                'attacker' => $combat['attacker'],
                'defender' => $combat['defender'],
            ];
        }
        $armyCost = static fn (array $army): int => array_sum(array_map(
            static fn (string $type, int $count): int => $costs[$type] * $count,
            array_keys($army),
            $army
        ));
        $request = [
            'schemaVersion' => 'waar-combat-batch-request/2',
            'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION,
            'ruleset' => $profile->ruleset(),
            'baseSeed' => 42,
            'iterations' => 100,
            'startIteration' => 0,
            'totalIterations' => 100,
            'consequences' => $combat['consequences'],
            'scenarios' => $scenarios,
        ];
        $document['cases'][$key.'/'.$name] = [
            'profile' => $key,
            'armyA' => $armyA,
            'armyB' => $armyB,
            'costA' => $armyCost($armyA),
            'costB' => $armyCost($armyB),
            'nominalBudget' => preg_match('/^mixed-(?:scale-)?(\d+)$/', $name, $matches) ? (int)$matches[1] : null,
            'totalUnits' => array_sum($armyA) + array_sum($armyB),
            'request' => $request,
        ];
    }
    $matrix = $factory->monotypes($profile, 'neutral', 42, 100);
    $document['cases'][$key.'/monotypes-16'] = [
        'profile' => $key,
        'scenarios' => 16,
        'budgetPerCamp' => 400400,
        'request' => $matrix,
    ];
}
if ($document['profiles']['nazz']['sha256'] !== '4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765') {
    throw new RuntimeException('Nazz campaign profile hash changed.');
}
if ($document['cases']['nazz/mixed-scale-800000']['totalUnits'] >= 500000) {
    throw new RuntimeException('Scale case exceeds the unit cap.');
}
$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$output = $argv[1] ?? null;
if ($output === null) {
    echo $json;
} elseif (count($argv) !== 2 || file_exists($output) || file_put_contents($output, $json) !== strlen($json)) {
    fwrite(STDERR, "Output must be a new writable path.\n");
    exit(2);
}
