<?php

use Waar\MicroCombat\Experiment\AcceptanceOverlayBuilder;
use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$experimentPath = $argv[1] ?? dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json';
$legacyPath = $argv[2] ?? $projectRoot.'/var/waar-micro-combat/t25a1/legacy-reference.json';
$outputDirectory = $argv[3] ?? $projectRoot.'/var/waar-micro-combat/t25b';

try {
    $experiment = ExperimentDefinition::fromFile($experimentPath);
    $legacyJson = @file_get_contents($legacyPath);
    if (false === $legacyJson) {
        throw new RuntimeException(sprintf('Unable to read Legacy reference "%s". Run tools/export-waar-micro-legacy-reference.php first.', $legacyPath));
    }
    $legacy = json_decode($legacyJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($legacy)) {
        throw new InvalidArgumentException('Legacy reference root must be an object.');
    }

    $calculationStarted = hrtime(true);
    $report = (new ExperimentRunner())->run($experiment);
    $calculationMilliseconds = (hrtime(true) - $calculationStarted) / 1_000_000;
    $overlayStarted = hrtime(true);
    $overlay = (new AcceptanceOverlayBuilder())->build($report, $legacy);
    $overlayMilliseconds = (hrtime(true) - $overlayStarted) / 1_000_000;

    $template = @file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay.html');
    $echartsBundle = @file_get_contents(dirname(__DIR__).'/resources/vendor/echarts-5.6.0.min.js');
    $zonesModel = @file_get_contents(dirname(__DIR__).'/resources/acceptance-zones-model.js');
    $application = @file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay-app.js');
    if (false === $template || false === $echartsBundle || false === $zonesModel || false === $application) {
        throw new RuntimeException('Unable to read the overlay template or its embedded scripts.');
    }
    $renderer = new AcceptanceOverlayRenderer();
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }
    $artifacts = [
        'experiment.json' => json_encode($experiment->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'micro-report.json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'legacy-reference.json' => $legacyJson,
        'acceptance-zones.json' => json_encode($overlay['zonesDocument'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'overlay.json' => json_encode($overlay, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'report.md' => $renderer->markdown($overlay),
        'report.html' => $renderer->html($overlay, $template, $echartsBundle, $zonesModel, $application),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "%s\n%d vecteurs · %d repères Legacy · %d zones éditables en brouillon\nCalcul micro : %.2f ms · fusion du calque : %.2f ms\nRapport humain : %s\n",
        $experiment->label,
        count($overlay['rows']),
        count($overlay['rows']),
        count($overlay['zonesDocument']['zones']),
        $calculationMilliseconds,
        $overlayMilliseconds,
        realpath($outputDirectory.'/report.html') ?: $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
