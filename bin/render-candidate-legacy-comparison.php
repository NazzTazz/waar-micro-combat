<?php

use Waar\MicroCombat\Experiment\CandidateLegacyComparisonBuilder;

require dirname(__DIR__).'/autoload.php';
$root = dirname(__DIR__);
$output = $argv[1] ?? $root.'/reports/t31-candidate-0116-legacy-comparison';
try {
    if ($argc > 2) {
        throw new InvalidArgumentException('Usage: php bin/render-candidate-legacy-comparison.php [empty-output-directory]');
    }
    if (file_exists($output) && (!is_dir($output) || count(scandir($output)) > 2)) {
        throw new RuntimeException('Output directory must be absent or empty.');
    }
    $references = $root.'/experiments/references/';
    $manifest = [];
    foreach (file($references.'manifest.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$hash, $path] = preg_split('/\s+/', trim($line), 2);
        $manifest[str_replace('\\', '/', $path)] = strtolower($hash);
    }
    $hashes = [];
    $read = static function (string $path) use ($references, $manifest, &$hashes): array {
        $raw = file_get_contents($references.$path);
        $hash = hash('sha256', $raw);
        if (($manifest[$path] ?? null) !== $hash) {
            throw new RuntimeException('Frozen reference hash mismatch: '.$path);
        }
        $hashes[$path] = $hash;
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    };
    $prefix = 't34-mixed-composition-observation/';
    $legacy = $read('t25a1/legacy-reference.json');
    $result = $read($prefix.'result.json');
    $plan = $read($prefix.'observation-plan.json');
    $corpus = $read($prefix.'inputs/t24-corpus.json');
    $variant = $read($prefix.'inputs/candidate-01.json');
    $original = $read('t31-standard-seed-314159/finalists/01-t31-candidate-0116-d0c5f6473d05/variant.json');
    foreach (['t24-corpus.json', 'candidate-01.json'] as $name) {
        $copy = $plan['frozenCopies'][$name] ?? [];
        if (($copy['path'] ?? null) !== 'inputs/'.$name || ($copy['sha256'] ?? null) !== $hashes[$prefix.'inputs/'.$name]) {
            throw new RuntimeException('Frozen T34 copy provenance mismatch: '.$name);
        }
    }
    if (count($corpus['scenarios']) !== count($plan['corpus']['scenarios'])) {
        throw new RuntimeException('Corpus scenario count mismatch.');
    }
    foreach ($corpus['scenarios'] as $index => $scenario) {
        foreach (['id', 'attacker', 'defender'] as $field) {
            $expected = $scenario[$field];
            if ('id' !== $field) {
                $expected = array_replace(['soldier' => 0, 'spearman' => 0, 'archer' => 0, 'knight' => 0], $expected);
            }
            if ($expected !== $plan['corpus']['scenarios'][$index][$field]) {
                throw new RuntimeException('Corpus and plan compositions differ.');
            }
        }
    }
    if (strtolower($result['planSha256']) !== $hashes[$prefix.'observation-plan.json'] || strtolower($plan['corpus']['sha256']) !== $hashes[$prefix.'inputs/t24-corpus.json'] || $variant !== $original) {
        throw new RuntimeException('Plan, corpus or candidate provenance mismatch.');
    }
    foreach ($variant['units'] as $unit => $definition) {
        if ($definition['cost'] !== $legacy['comparisonProfile']['valuation'][$unit]) {
            throw new RuntimeException('Candidate valuation mismatch.');
        }
    }
    $planned = array_values(array_filter($plan['variants'], static fn(array $v): bool => $v['id'] === CandidateLegacyComparisonBuilder::CANDIDATE_ID));
    $measured = array_values(array_filter($result['finalists'], static fn(array $v): bool => $v['id'] === CandidateLegacyComparisonBuilder::CANDIDATE_ID));
    if (count($planned) !== 1 || count($measured) !== 1 || $planned[0]['parameterFingerprint'] !== $measured[0]['parameterFingerprint'] || strtolower($planned[0]['sha256']) !== $hashes[$prefix.'inputs/candidate-01.json']) {
        throw new RuntimeException('Candidate identity mismatch.');
    }
    $data = (new CandidateLegacyComparisonBuilder())->build($legacy, $result, $plan);
    $data['inputSha256'] = $hashes;
    $encode = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)."\n";
    $html = str_replace(['__ECHARTS__', '__DATA__'], [file_get_contents($root.'/resources/vendor/echarts-5.6.0.min.js'), $encode($data)], file_get_contents($root.'/resources/candidate-legacy-comparison.html'));
    if (!is_dir($output) && !mkdir($output, 0777, true)) {
        throw new RuntimeException('Unable to create output directory.');
    }
    foreach (['report.html' => $html, 'comparison.json' => $encode($data), 'input-sha256.json' => $encode($hashes), 'report.md' => "# Candidat 116 / Legacy — T24\n\n12 vecteurs Legacy → candidat 116. Vue brute et pertes Legacy dilatées ×20 : Y = 100 × (1 − 20 × (1 − ratio)). X et candidat inchangés. Effectifs et valeur économique opérationnels, pas de structure Legacy comparable. Indices négatifs conservés.\n\nSources archivées vérifiées, aucune simulation relancée. Legacy : 200 répétitions, seed 42 ; candidat : 1 000 répétitions, seed 32452843. Aucun appariement statistique inter-moteurs. Les arrondis Legacy des petites cohortes expliquent les pertes dépassant 5 %. Aucun critère μ − 2σ évalué avec ces seules moyennes.\n\nLe candidat reste exploratoire (T33 : 0/32). La correction technique R1 ne vaut pas approbation du candidat. Aucun objectif ni zone d’acceptance modifié. Les deltas signés sont candidat moins Legacy : victoire en points de pourcentage, effectifs contre Legacy dilaté ×20 en points d’indice. Scores globaux à poids égaux sur tout T24 : proportion de scénarios avec le même camp majoritaire (>50 %), puis max(0, 100 − écart absolu moyen) pour les taux de victoire et les effectifs contre Legacy ×20. Aucun verdict statistique ni seuil d’acceptation. Les valeurs sont dans comparison.json.globalConformity. Voir input-sha256.json pour les sources.\n"] as $name => $contents) {
        if (false === file_put_contents($output.'/'.$name, $contents)) {
            throw new RuntimeException('Unable to write '.$name);
        }
    }
    $notes = "\n## Lecture des confrontations\n\nCompositions en pourcentage des effectifs initiaux ; variation relative des pertes contre le Legacy dilaté ×20.\n\n";
    foreach ($data['scenarioNotes'] as $note) {
        $notes .= '### '.$note['label']."\n\n".$note['text']."\n\n";
    }
    $notes .= "## Rappel T24 et coûts égaux\n\nLa recherche T31 a utilisé les 16 confrontations monotypes des quatre unités à coûts égaux : 400 400 par camp. Le corpus T24 contient six compositions historiques inchangées, avec des budgets parfois inégaux. Les mesures du candidat sur T24 proviennent de T34. T24 sert à observer les effets de l’équilibrage et ne fournit aucun objectif ni score à la recherche T31. Le candidat reste exploratoire.\n";
    if (false === file_put_contents($output.'/report.md', $notes, FILE_APPEND)) {
        throw new RuntimeException('Unable to write scenario notes.');
    }
    echo $output.'/report.html'."\n12 vectors generated from verified archives.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
