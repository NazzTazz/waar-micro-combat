<?php

use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\ReportRenderer;

require dirname(__DIR__).'/autoload.php';

$configPath = $argv[1] ?? dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json';
$outputDirectory = $argv[2] ?? dirname(__DIR__, 3).'/var/waar-micro-combat/t24';

try {
    $experiment = ExperimentDefinition::fromFile($configPath);
    $report = (new ExperimentRunner())->run($experiment);
    $renderer = new ReportRenderer();
    $template = file_get_contents(dirname(__DIR__).'/resources/wind-tunnel.html');
    if (false === $template) {
        throw new RuntimeException('Unable to read the wind tunnel HTML template.');
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }
    $artifacts = [
        'experiment.json' => json_encode($experiment->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'report.json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        'report.md' => $renderer->markdown($report),
        'report.html' => $renderer->html($report, $template),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "%s\n%d scénarios · %d itérations appariées · %d combats\nRapport humain : %s\n",
        $experiment->label,
        count($experiment->scenarios),
        $experiment->iterations,
        $report['experiment']['combatCount'],
        realpath($outputDirectory.'/report.html') ?: $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
