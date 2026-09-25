<?php
declare(strict_types=1);

/* Configuration audit only: no ProcessCohortRuntime and no combat calls. */
use Waar\MicroCombat\Exploration\ParametricCampaign;
use Waar\MicroCombat\Workshop\EngineProfile;

$root = dirname(__DIR__, 2);
require $root.'/autoload.php';
$folder = __DIR__;
$destination = $root.'/reports/campagne-coeur/preparation';
$profilePath = $root.'/reports/campaign-manual-sol/profile.json';
$expectedProfileHash = '4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765';
foreach ([$profilePath, $folder.'/reference-profile.json'] as $path) {
    if (!is_file($path) || hash_file('sha256', $path) !== $expectedProfileHash) {
        throw new RuntimeException("Profil authentique absent ou différent : {$path}");
    }
}
$profile = json_decode(file_get_contents($profilePath), true, 128, JSON_THROW_ON_ERROR);
EngineProfile::fromArray($profile);
if ($profile['combat']['woundDamageThreshold'] !== '0.2') {
    throw new RuntimeException('Le seuil de blessure de référence doit être 0.2.');
}

$sourceManifest = json_decode(file_get_contents($folder.'/campaign-manifest.json'), true, 128, JSON_THROW_ON_ERROR);
$phaseByPlan = array_column($sourceManifest['plans'], 'phase', 'plan');
$files = glob($folder.'/*.json') ?: [];
sort($files, SORT_STRING);
$plans = $warnings = $errors = $duplicates = [];
$firstRequest = $paths = $weatherContexts = [];
$totals = ['plans'=>0, 'configurations'=>0, 'directions'=>0, 'combats'=>0, 'warnings'=>0,
    'uniqueDirectionRequests'=>0, 'duplicateDirectionRequests'=>0, 'uniqueDirectionCombats'=>0, 'duplicateDirectionCombats'=>0];
$byPhase = [];

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, ['reference-profile.json', 'campaign-design.json', 'campaign-manifest.json'], true)) continue;
    try {
        $loaded = ParametricCampaign::load($file);
        $plan = $loaded['plan'];
        $preview = ParametricCampaign::preview($plan, $loaded['profile']);
        $phase = $phaseByPlan[$name] ?? (preg_match('/^(D[345])-/', $name, $match) ? $match[1] : 'unclassified');
        $axisPaths = array_values(array_unique(array_column($plan['axes'], 'path')));
        foreach ($axisPaths as $path) $paths[$path][$name] = true;
        foreach ($plan['scenarios'] as $scenario) {
            foreach (['A', 'B'] as $camp) $weatherContexts[$scenario['weather'][$camp]][$name] = true;
        }
        $row = [
            'plan'=>$name, 'planSha256'=>hash_file('sha256', $file), 'phase'=>$phase, 'scenarioCount'=>count($plan['scenarios']),
            'axisPaths'=>implode('|', $axisPaths), 'configurations'=>$preview['experiments'],
            'directions'=>$preview['directions'], 'repetitionsPerDirection'=>$plan['sampling']['repetitions'],
            'batchSize'=>$plan['sampling']['batchSize'], 'combats'=>$preview['combats'],
            'maxCombats'=>$plan['limits']['maxCombats'], 'headroom'=>$plan['limits']['maxCombats']-$preview['combats'],
            'warningCount'=>count($preview['warnings']), 'dependency'=>dependency($phase), 'status'=>'accepted',
        ];
        $plans[] = $row;
        $totals['plans']++;
        $totals['configurations'] += $preview['experiments'];
        $totals['directions'] += $preview['directions'];
        $totals['combats'] += $preview['combats'];
        $totals['warnings'] += count($preview['warnings']);
        foreach (['plans', 'configurations', 'directions', 'combats'] as $field) {
            $byPhase[$phase][$field] = ($byPhase[$phase][$field] ?? 0) + ($field === 'plans' ? 1 : $row[$field]);
        }
        foreach ($preview['warnings'] as $warning) $warnings[] = ['plan'=>$name, 'warning'=>$warning];
        foreach (ParametricCampaign::experiments($plan, $loaded['profile']) as $experiment) {
            $request = ParametricCampaign::batchRequest($experiment, $plan['sampling']['baseSeed'], 0, 1, $plan['sampling']['repetitions']);
            foreach ($request['scenarios'] as $scenario) {
                $single = $request;
                $single['scenarios'] = [$scenario];
                $key = hash('sha256', ParametricCampaign::canonicalJson($single));
                $alias = ['plan'=>$name, 'experimentId'=>$experiment['id'], 'scenarioId'=>$experiment['scenarioId'], 'direction'=>$scenario['id']];
                if (!isset($firstRequest[$key])) {
                    $firstRequest[$key] = $alias;
                    $totals['uniqueDirectionRequests']++;
                    $totals['uniqueDirectionCombats'] += $plan['sampling']['repetitions'];
                } else {
                    $duplicates[] = ['requestSha256'=>$key, 'firstPlan'=>$firstRequest[$key]['plan'],
                        'firstExperimentId'=>$firstRequest[$key]['experimentId'], 'firstScenarioId'=>$firstRequest[$key]['scenarioId'],
                        'firstDirection'=>$firstRequest[$key]['direction'], 'aliasPlan'=>$name,
                        'aliasExperimentId'=>$alias['experimentId'], 'aliasScenarioId'=>$alias['scenarioId'],
                        'aliasDirection'=>$alias['direction']];
                    $totals['duplicateDirectionRequests']++;
                    $totals['duplicateDirectionCombats'] += $plan['sampling']['repetitions'];
                }
            }
        }
        fwrite(STDERR, "\rValidated {$totals['plans']} plans, {$totals['configurations']} configurations");
    } catch (Throwable $error) {
        $errors[] = ['plan'=>$name, 'error'=>$error->getMessage()];
        fwrite(STDERR, "\nRejected {$name}: {$error->getMessage()}\n");
    }
}
fwrite(STDERR, "\n");
$totals['rejectedPlans'] = count($errors);
ksort($byPhase);

$coverage = [];
foreach ($profile['units'] as $unit=>$fields) foreach ($fields as $field=>$value) {
    $coverage[] = coverageRow('unit', "units.{$unit}.{$field}", $value, $paths);
}
$types = array_keys($profile['units']);
foreach ($types as $source) foreach ($types as $target) if ($source !== $target) {
    $value = '1 (implicit)';
    foreach ($profile['relations'] as $relation) if ($relation['acting'] === $source && $relation['target'] === $target) $value = $relation['factor'];
    $coverage[] = coverageRow('counter', "relations.{$source}>{$target}.factor", $value, $paths);
}
foreach ($profile['combat'] as $field=>$value) {
    $path = 'combat.'.$field;
    $lookup = in_array($field, ['surrenderEnabled', 'surrenderDeadPercent'], true) ? 'combat.surrender' : $path;
    $coverage[] = coverageRow('combat', $path, $value, $paths, $lookup);
}
foreach ($profile['weather'] as $weather=>$units) foreach ($units as $unit=>$fields) foreach ($fields as $field=>$value) {
    $coverage[] = coverageRow('weather-factor', "weather.{$weather}.{$unit}.{$field}", $value, $paths);
}
foreach (EngineProfile::WEATHER as $weather) {
    $coverage[] = ['kind'=>'scenario-weather', 'path'=>'scenario.weather.'.$weather, 'referenceValue'=>'',
        'axisPlans'=>'', 'contextPlans'=>implode('|', array_keys($weatherContexts[$weather] ?? [])),
        'status'=>isset($weatherContexts[$weather]) ? 'scenario-covered' : 'missing'];
}

$segmentManifestPath = $folder.'/segments/segments-manifest.json';
if (!is_file($segmentManifestPath)) throw new RuntimeException('Segments absents : exécuter split-large-plans.php.');
$segmentManifest = json_decode(file_get_contents($segmentManifestPath), true, 128, JSON_THROW_ON_ERROR);
$replaced = array_fill_keys(array_map(fn($name)=>$name.'.json', $segmentManifest['sourcePlans']), true);
$phaseLookup = array_column($plans, 'phase', 'plan');
$active = [];
foreach ($plans as $row) if (!isset($replaced[$row['plan']])) {
    $active[] = ['plan'=>$row['plan'], 'phase'=>$row['phase'], 'sourcePlan'=>'',
        'configurations'=>$row['configurations'], 'directions'=>$row['directions'],
        'combats'=>$row['combats'], 'maxCombats'=>$row['maxCombats'],
        'dependency'=>$row['dependency'], 'planSha256'=>$row['planSha256'], 'status'=>'previewed'];
}
foreach ($segmentManifest['rows'] as $segment) {
    $active[] = ['plan'=>'segments/'.$segment['segment'], 'phase'=>$phaseLookup[$segment['source']],
        'sourcePlan'=>$segment['source'], 'configurations'=>$segment['configurations'],
        'directions'=>$segment['directions'], 'combats'=>$segment['combats'],
        'maxCombats'=>$segment['maxCombats'], 'dependency'=>dependency($phaseLookup[$segment['source']]),
        'planSha256'=>$segment['sha256'], 'status'=>'previewed'];
}
$phaseOrder = array_flip(['M0','D1','D2','D3','D4','D5','M','W','E','X']);
usort($active, fn($a,$b)=>[$phaseOrder[$a['phase']], $a['plan']] <=> [$phaseOrder[$b['phase']], $b['plan']]);
$activeTotals = ['plans'=>count($active), 'configurations'=>array_sum(array_column($active, 'configurations')),
    'directions'=>array_sum(array_column($active, 'directions')), 'combats'=>array_sum(array_column($active, 'combats'))];
foreach (['configurations','directions','combats'] as $field) if ($activeTotals[$field] !== $totals[$field]) {
    throw new RuntimeException("Partition active incompatible : {$field}");
}

if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) throw new RuntimeException('Création des sorties impossible.');
writeCsv($destination.'/preview-summary.csv', $plans,
    ['plan','planSha256','phase','scenarioCount','axisPaths','configurations','directions','repetitionsPerDirection','batchSize','combats','maxCombats','headroom','warningCount','dependency','status']);
writeCsv($destination.'/coverage.csv', $coverage,
    ['kind','path','referenceValue','axisPlans','contextPlans','status']);
writeCsv($destination.'/duplicate-requests.csv', $duplicates,
    ['requestSha256','firstPlan','firstExperimentId','firstScenarioId','firstDirection','aliasPlan','aliasExperimentId','aliasScenarioId','aliasDirection']);
writeCsv($destination.'/preview-warnings.csv', $warnings, ['plan','warning']);
writeCsv($destination.'/rejected-plans.csv', $errors, ['plan','error']);
writeCsv($destination.'/active-plans.csv', $active,
    ['plan','phase','sourcePlan','configurations','directions','combats','maxCombats','dependency','planSha256','status']);
$manifest = ['schemaVersion'=>'waar-campaign-preparation/1', 'status'=>$errors === [] ? 'previewed' : 'partial',
    'sourceZipSha256'=>hash_file('sha256', $root.'/reports/campaign-manual-sol/incoming/campagne-waar-configuration.zip'),
    'profileSha256'=>$expectedProfileHash, 'profilePath'=>$profilePath, 'profileId'=>$profile['id'],
    'codeHead'=>trim((string)shell_exec('git rev-parse HEAD')), 'combatsExecuted'=>0,
    'totals'=>$totals, 'activeTotals'=>$activeTotals, 'byPhase'=>$byPhase, 'plans'=>$plans, 'rejections'=>$errors,
    'duplicateDefinition'=>'Identical canonical single-direction batch request at iteration 0, including ruleset, consequences, base seed, totalIterations, identities and modifiers; scenario aliases are listed separately.',
    'deferred'=>['D3 existing combat traces and injured units continuing to strike require later simulation',
        'Selected pair/triple interactions require discriminating points from measured single axes',
        'Adaptive refinement and independent 8000 repetition confirmations require measured results']];
file_put_contents($destination.'/campaign-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['status'=>$manifest['status'], 'totals'=>$totals, 'activeTotals'=>$activeTotals, 'byPhase'=>$byPhase,
    'coverageRows'=>count($coverage), 'uncoveredProfileControls'=>count(array_filter($coverage, fn($r)=>$r['status']==='missing'))],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), "\n";
if ($errors !== []) exit(1);

function dependency(string $phase): string {
    return match ($phase) {'M0'=>'', 'D1','D2'=>'M0', 'D3','D4','D5'=>'D1|D2', 'M','W'=>'D1|D2|D3|D4|D5', 'E','X'=>'M|W', default=>''};
}
function coverageRow(string $kind, string $path, mixed $reference, array $paths, ?string $lookup=null): array {
    $plans = array_keys($paths[$lookup ?? $path] ?? []);
    return ['kind'=>$kind, 'path'=>$path, 'referenceValue'=>is_scalar($reference) ? (is_bool($reference) ? ($reference ? 'true' : 'false') : (string)$reference) : json_encode($reference),
        'axisPlans'=>implode('|', $plans), 'contextPlans'=>'', 'status'=>$plans === [] ? 'missing' : 'axis-covered'];
}
function writeCsv(string $path, array $rows, array $header): void {
    $handle = fopen($path, 'wb');
    if ($handle === false) throw new RuntimeException("Écriture impossible : {$path}");
    try { fputcsv($handle, $header); foreach ($rows as $row) fputcsv($handle, array_map(fn($key)=>$row[$key] ?? '', $header)); }
    finally { fclose($handle); }
}
