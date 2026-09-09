<?php

use Waar\MicroCombat\Experiment\CanonicalMonotypeSearchObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__);
$objectivePath = $argv[1] ?? $projectRoot.'/experiments/objectives/20260909-po-design-01-canonical/acceptance-zones.json';
$experimentPath = $argv[2] ?? dirname(__DIR__).'/experiments/t28-defender-tie-break.json';
$outputDirectory = $argv[3] ?? $projectRoot.'/reports/t29-search-objective';

try {
    $objectiveJson = @file_get_contents($objectivePath);
    if (false === $objectiveJson) {
        throw new RuntimeException(sprintf('Unable to read objective document "%s".', $objectivePath));
    }
    $objectives = json_decode($objectiveJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($objectives) || array_is_list($objectives)) {
        throw new InvalidArgumentException('The objective document must be a JSON object.');
    }

    $experiment = ExperimentDefinition::fromFile($experimentPath);
    $report = (new ExperimentRunner())->run($experiment);
    $evaluation = (new CanonicalMonotypeSearchObjectiveEvaluator())->evaluate($report, $objectives);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }

    $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $artifacts = [
        'experiment.json' => $encode($experiment->toArray()),
        'micro-report.json' => $encode($report),
        'objectives.json' => $objectiveJson,
        'search-evaluation.json' => $encode($evaluation),
        'report.md' => implode("\n", [
            '# Objectif continu de la recherche monotype',
            '',
            sprintf('Candidat `%s@%s` · départage `%s`.', $evaluation['candidate']['id'], $evaluation['candidate']['version'], $evaluation['candidate']['tieBreakPolicy']),
            '',
            sprintf('Perte continue moyenne : %.12f rayon normalisé.', $evaluation['continuousObjective']['value']),
            sprintf('Pire écart : %.12f rayon normalisé · `%s`.', $evaluation['continuousObjective']['worst']['value'], $evaluation['continuousObjective']['worst']['objectiveId']),
            sprintf('Objectifs satisfaits : %d / %d (%.1f %%).', $evaluation['acceptance']['satisfied'], $evaluation['acceptance']['total'], 100 * $evaluation['acceptance']['rate']),
            sprintf('Nuls : %d / %d combats (%.1f %%).', $evaluation['draws']['count'], $evaluation['draws']['combatCount'], 100 * $evaluation['draws']['rate']),
            '',
            sprintf('Contrôles stricts : objectifs `%s`, absence de nuls `%s`, global `%s`.', $evaluation['strictControls']['allObjectivesSatisfied'] ? 'OK' : 'ÉCHEC', $evaluation['strictControls']['noDraws'] ? 'OK' : 'ÉCHEC', $evaluation['strictControls']['passed'] ? 'OK' : 'ÉCHEC'),
            '',
            '> Chaque zone a le poids 1/32. Sa pénalité est nulle dans l’ellipse, puis égale à la distance radiale normalisée au-delà de la frontière numérique acceptée. Aucun paramètre n’est recherché par cette commande.',
            '',
        ]),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "Objectif monotype : perte %.12f · pire écart %.12f · %d/%d objectifs · contrôles stricts %s\nRapport : %s\n",
        $evaluation['continuousObjective']['value'],
        $evaluation['continuousObjective']['worst']['value'],
        $evaluation['acceptance']['satisfied'],
        $evaluation['acceptance']['total'],
        $evaluation['strictControls']['passed'] ? 'OK' : 'ÉCHEC',
        realpath($outputDirectory.'/report.md') ?: $outputDirectory.'/report.md',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
