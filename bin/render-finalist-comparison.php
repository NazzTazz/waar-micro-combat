<?php

use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\FinalistComparisonBuilder;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__);
$runDirectory = $argv[1] ?? $projectRoot.'/experiments/references/t31-standard-seed-314159';
$initialReportPath = $argv[2] ?? $projectRoot.'/experiments/references/t28-defender-tie-break/micro-report.json';
$outputDirectory = $argv[3] ?? $projectRoot.'/reports/t32-finalist-comparison';

try {
    if (is_dir($outputDirectory) && [] !== array_values(array_diff(scandir($outputDirectory) ?: [], ['.', '..']))) {
        throw new RuntimeException(sprintf('Output directory "%s" must be absent or empty.', $outputDirectory));
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }

    $comparison = (new FinalistComparisonBuilder())->buildFromDirectory($runDirectory, $initialReportPath);
    $resourceDirectory = dirname(__DIR__).'/resources';
    $template = file_get_contents($resourceDirectory.'/finalist-comparison.html');
    $echarts = file_get_contents($resourceDirectory.'/vendor/echarts-5.6.0.min.js');
    $model = file_get_contents($resourceDirectory.'/finalist-comparison-model.js');
    $application = file_get_contents($resourceDirectory.'/finalist-comparison-app.js');
    if (false === $template || false === $echarts || false === $model || false === $application) {
        throw new RuntimeException('Unable to read the T32 template or embedded scripts.');
    }

    $renderer = new AcceptanceOverlayRenderer();
    $json = json_encode($comparison, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $artifacts = [
        'presentation.json' => $json,
        'report.md' => $renderer->finalistComparisonMarkdown($comparison),
        'report.html' => $renderer->finalistComparisonHtml($comparison, $template, $echarts, $model, $application),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "%s\n%s\n%d finalistes · %d confrontations · %d objectifs · zéro simulation T32\nRapport : %s\n",
        $comparison['title'],
        $comparison['run']['statusLabel'],
        count($comparison['finalists']),
        count($comparison['scenarios']),
        $comparison['objectiveDocument']['count'],
        realpath($outputDirectory.'/report.html') ?: $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
