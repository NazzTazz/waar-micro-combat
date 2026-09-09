<?php

use Waar\MicroCombat\Experiment\CanonicalMonotypeObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$objectivePath = $argv[1] ?? $projectRoot.'/var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json';
$experimentPath = $argv[2] ?? dirname(__DIR__).'/experiments/t26-monotype-equal-cost.json';
$outputDirectory = $argv[3] ?? $projectRoot.'/var/waar-micro-combat/t27b-objective-preflight';

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
    $evaluation = (new CanonicalMonotypeObjectiveEvaluator())->evaluate($report, $objectives);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }

    $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $artifacts = [
        'experiment.json' => $encode($experiment->toArray()),
        'micro-report.json' => $encode($report),
        'objectives.json' => $objectiveJson,
        'evaluation.json' => $encode($evaluation),
        'report.md' => implode("\n", [
            '# Prévol de la recherche monotype',
            '',
            sprintf('Candidat `%s@%s` · départage `%s`.', $evaluation['candidate']['id'], $evaluation['candidate']['version'], $evaluation['candidate']['tieBreakPolicy']),
            '',
            sprintf('Objectifs satisfaits : %d / %d (%.1f %%).', $evaluation['score']['satisfied'], $evaluation['score']['total'], 100 * $evaluation['score']['rate']),
            sprintf('Nuls : %d / %d combats (%.1f %%) dans %d scénario%s.', $evaluation['draws']['count'], $evaluation['draws']['combatCount'], 100 * $evaluation['draws']['rate'], $evaluation['draws']['scenarioCount'], 1 === $evaluation['draws']['scenarioCount'] ? '' : 's'),
            '',
            sprintf('Contrôles stricts : objectifs `%s`, absence de nuls `%s`, prévol global `%s`.', $evaluation['strictControls']['allObjectivesSatisfied'] ? 'OK' : 'ÉCHEC', $evaluation['strictControls']['noDraws'] ? 'OK' : 'ÉCHEC', $evaluation['strictControls']['passed'] ? 'OK' : 'ÉCHEC'),
            '',
            '> Chaque objectif survivants contribue exactement une fois. Valeur économique et structure ne créent aucune contribution supplémentaire. Aucun paramètre n’est recherché par cette commande.',
            '',
        ]),
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "Prévol monotype : %d/%d objectifs satisfaits · %d/%d nuls · contrôles stricts %s\nRapport : %s\n",
        $evaluation['score']['satisfied'],
        $evaluation['score']['total'],
        $evaluation['draws']['count'],
        $evaluation['draws']['combatCount'],
        $evaluation['strictControls']['passed'] ? 'OK' : 'ÉCHEC',
        realpath($outputDirectory.'/report.md') ?: $outputDirectory.'/report.md',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
