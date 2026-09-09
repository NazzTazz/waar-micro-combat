<?php

use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeObjectiveOverlayBuilder;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__);
$experimentPath = $argv[1] ?? dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json';
$outputDirectory = $argv[2] ?? $projectRoot.'/reports/t26-canonical-objectives';

try {
    $experiment = ExperimentDefinition::fromFile($experimentPath);
    $calculationStarted = hrtime(true);
    $report = (new ExperimentRunner())->run($experiment);
    $calculationMilliseconds = (hrtime(true) - $calculationStarted) / 1_000_000;
    $overlayStarted = hrtime(true);
    $overlay = (new MonotypeObjectiveOverlayBuilder())->build($report);
    $overlayMilliseconds = (hrtime(true) - $overlayStarted) / 1_000_000;

    $template = @file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay.html');
    $echartsBundle = @file_get_contents(dirname(__DIR__).'/resources/vendor/echarts-5.6.0.min.js');
    $zonesModel = @file_get_contents(dirname(__DIR__).'/resources/acceptance-zones-model.js');
    $application = @file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay-app.js');
    if (false === $template || false === $echartsBundle || false === $zonesModel || false === $application) {
        throw new RuntimeException('Unable to read the objective workbench template or its embedded scripts.');
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }

    $renderer = new AcceptanceOverlayRenderer();
    $artifacts = [
        'experiment.json' => json_encode($experiment->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'micro-report.json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'acceptance-zones.json' => json_encode($overlay['zonesDocument'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'overlay.json' => json_encode($overlay, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'report.md' => implode("\n", [
            '# Objectifs monotypes à coût égal — éditeur par paire',
            '',
            sprintf('Matrice ordonnée 4 × 4, budget exact de %d par camp, %d simulations par variante et scénario.', $overlay['designSurface']['commonBudget'], $experiment->iterations),
            '',
            sprintf('%d zones de pointe en brouillon : X attaquant repris de `%s`, X défenseur complémentaire à 100 %% ; les Y reprennent les observations comme point de départ éditorial.', count($overlay['zonesDocument']['zones']), $experiment->candidate->id),
            '',
            '> T24 et Legacy ne fournissent aucune contrainte ni aucun score à cette passe. Aucun solveur ni sélection de paramètres n’est exécuté.',
            '',
        ]),
        'report.html' => $renderer->html($overlay, $template, $echartsBundle, $zonesModel, $application),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "%s\n%d confrontations ordonnées · budget exact %d · %d zones de pointe en brouillon\nCalcul micro : %.2f ms · construction des objectifs : %.2f ms\nRapport humain : %s\n",
        $experiment->label,
        $overlay['designSurface']['scenarioCount'],
        $overlay['designSurface']['commonBudget'],
        count($overlay['zonesDocument']['zones']),
        $calculationMilliseconds,
        $overlayMilliseconds,
        realpath($outputDirectory.'/report.html') ?: $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
