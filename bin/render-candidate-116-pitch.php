<?php

// Offline, descriptive presentation. No simulations or acceptance decisions.
require dirname(__DIR__).'/autoload.php';

use Waar\MicroCombat\Experiment\FinalistComparisonBuilder;
use Waar\MicroCombat\Experiment\AcceptanceOverlayBuilder;
use Waar\MicroCombat\Experiment\LegacyMonotypePitchCoordinates;

$root = dirname(__DIR__);
$output = $argv[1] ?? $root.'/reports/candidate-116-pitch';
try {
    if ($argc > 3 || (file_exists($output) && (!is_dir($output) || count(scandir($output)) > 2))) {
        throw new RuntimeException('Usage: php bin/render-candidate-116-pitch.php [absent-or-empty-directory] [legacy-observation-directory]');
    }
    $run = $root.'/experiments/references/t31-standard-seed-314159';
    $comparison = (new FinalistComparisonBuilder())->buildFromDirectory($run, $root.'/experiments/references/t28-defender-tie-break/micro-report.json');
    $matches = array_values(array_filter($comparison['finalists'], static fn (array $v): bool => $v['id'] === 't31-candidate-0116-d0c5f6473d05'));
    if (count($matches) !== 1) {
        throw new RuntimeException('Candidate 116 missing or duplicated.');
    }
    $candidate = $matches[0];
    $legacyDirectory = $argv[2] ?? $root.'/reports/legacy-monotypes-116';
    $read = static fn (string $path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $manifest = $read($legacyDirectory.'/manifest.json');
    foreach (['plan.json', 'legacy-reference.json'] as $file) {
        if (($manifest[$file] ?? null) !== hash_file('sha256', $legacyDirectory.'/'.$file)) {
            throw new RuntimeException('Legacy artifact hash mismatch.');
        }
    }
    $legacy = $read($legacyDirectory.'/legacy-reference.json');
    $plan = $read($legacyDirectory.'/plan.json');
    if ($plan['experimentSha256'] !== hash_file('sha256', $run.'/experiment.json')) {
        throw new RuntimeException('Legacy corpus plan mismatch.');
    }
    $micro = $read($run.'/finalists/01-t31-candidate-0116-d0c5f6473d05/micro-report.json');
    (new AcceptanceOverlayBuilder())->build($micro, $legacy); // Validate exact corpus, pairing and sampling; discard PO geometry.
    $legacyRows = [];
    foreach ($legacy['rows'] as $row) {
        $legacyRows[$row['scenarioId'].'/'.$row['side']] = $row;
    }
    $coordinates = new LegacyMonotypePitchCoordinates();
    $names = ['soldier' => 'Soldat', 'spearman' => 'Lancier', 'archer' => 'Archer', 'knight' => 'Chevalier'];
    $scenarios = [];
    foreach ($comparison['scenarios'] as $scenario) {
        [$a, $d] = explode('-vs-', $scenario['id']);
        $rows = array_values(array_filter($candidate['rows'], static fn (array $row): bool => $row['scenarioId'] === $scenario['id']));
        $sides = [];
        foreach ($rows as $row) {
            $observation = $row['observation'];
            $l = $legacyRows[$row['scenarioId'].'/'.$row['side']];
            $point = $coordinates->build($l['winRate']['value'], $l['metrics']['operationalSurvivorsRatio']['value'], $observation['winRate'], $observation['survivors']);
            if ($point['legacy'][1] > 110) {
                throw new RuntimeException('Legacy point outside fixed display domain.');
            }
            $sides[] = ['label' => $row['side'] === 'attacker' ? 'Attaquant' : 'Défenseur'] + $point;
        }
        $fmt = static fn (float $n): string => number_format(abs($n), 1, ',', ' ');
        $notes = [];
        foreach ($sides as $side) {
            $notes[] = $side['label'].' : '.$fmt($side['deltaWin']).' points de victoire '.($side['deltaWin'] >= 0 ? 'de plus' : 'de moins').' ; '.$fmt($side['deltaLoss']).' points de pertes '.($side['deltaLoss'] >= 0 ? 'au-dessus' : 'en dessous').' du repère Legacy ×20.';
        }
        $scenarios[] = ['id' => $scenario['id'], 'label' => $names[$a].' attaque '.$names[$d], 'sides' => $sides, 'note' => implode(' ', $notes)];
    }
    $encode = static fn ($data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT);
    $data = ['schemaVersion' => 'waar-legacy-monotype-pitch/0.1', 'candidateId' => $candidate['id'], 'scenarios' => $scenarios, 'factor' => 20, 'ellipseRadii' => [5, 10], 'acceptanceEvaluated' => false, 'sourceHashes' => $manifest];
    $graph = '<p><strong>Ellipse et losange : Legacy ×20. Flèche vers le rond : candidat 116.</strong> Vert : attaquant ; orange : défenseur. Plus haut signifie davantage de pertes ; plus à droite signifie davantage de victoires.</p>'
        .'<label for="duel">Confrontation </label><select id="duel"></select><div id="chart" role="img" aria-label="Vecteurs depuis les ellipses Legacy vers le candidat 116"></div><p id="duel-note"></p><div class="scroll"><table><thead><tr><th>Camp</th><th>Victoires Legacy → 116</th><th>Pertes Legacy réelles</th><th>Legacy ×20 → pertes 116</th></tr></thead><tbody id="values"></tbody></table></div>'
        .'<p class="small">Mêmes effectifs dans les deux moteurs. Budget commun de comparaison : 400 400 par camp, soit 5 005 soldats, 3 640 lanciers, 3 080 archers ou 1 144 chevaliers. Ce budget utilise les coûts du candidat ; les coûts natifs Legacy sont différents. 200 répétitions par duel et moteur, seed de base 42, sans appariement statistique inter-moteurs.</p>'
        .'<details><summary>Comment lire la dilation et les ellipses</summary><p>Y Legacy = 20 × pertes en %. Exemple : 2 % deviennent 40 points d’indice. Y candidat = pertes réelles en %. X reste le taux de victoire. Un écart vertical compare des profils après dilation, pas des pertes physiques équivalentes.</p><p>Les centres viennent des mesures Legacy. Les rayons visuels sont fixes : 5 points de victoire et 10 points d’indice de pertes, sur le graphe final. Ils servent à repérer les centres : ce ne sont ni une dispersion mesurée, ni un intervalle de confiance, ni un seuil d’acceptation. Les ellipses débordent légèrement de [0,100] pour rester visibles lorsque le Legacy est à 0 ou 100 % de victoire.</p></details>';
    $provenance = '<p>Candidat : <code>experiments/references/t31-standard-seed-314159/finalists/01-t31-candidate-0116-d0c5f6473d05/micro-report.json</code> et <code>variant.json</code>. Empreintes contrôlées par le constructeur T32. Legacy : nouvelle observation locale des 16 mêmes duels, 3 200 combats, après reproduction des 1 200 combats T24 archivés. Plan et résultats dans <code>reports/legacy-monotypes-116</code> ; aucune référence figée modifiée.</p><p>Oracle Legacy : <code>../waar-v3/src/Game/Combat/LegacyAggregateCombatResolver.php</code>, empreintes identiques à la référence historique. Règles micro : <code>src/CombatResolver.php</code>. Validation T33 : <code>docs/waar-micro-combat-t33-relay.md</code>.</p><p>Legacy : pertes opérationnelles avant hôpital, blessés et morts exclus des effectifs restants. Micro : unités survivantes, même partiellement endommagées. Le plafond théorique Legacy avant arrondi est d’environ 4,12 % ; son arrondi par cohorte peut le dépasser. Le facteur ×20 sert uniquement à rendre ces petites pertes lisibles.</p>';
    $scripts = '<script>'.file_get_contents($root.'/resources/vendor/echarts-5.6.0.min.js').'</script><script id="pitch-data" type="application/json">'.$encode($data).'</script><script>'.file_get_contents($root.'/resources/candidate-116-pitch.js').'</script>';
    $html = str_replace(['__GRAPH__', '__PROVENANCE__', '__SCRIPTS__'], [$graph, $provenance, $scripts], file_get_contents($root.'/resources/candidate-116-pitch.html'));
    if (!is_dir($output) && !mkdir($output, 0777, true)) {
        throw new RuntimeException('Cannot create output.');
    }
    foreach (['report.html' => $html, 'presentation.json' => $encode($data)] as $name => $content) {
        if (file_put_contents($output.'/'.$name, $content) === false) {
            throw new RuntimeException('Cannot write '.$name);
        }
    }
    echo $output.'/report.html'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
