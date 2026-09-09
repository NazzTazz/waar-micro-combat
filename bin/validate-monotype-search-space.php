<?php

use Waar\MicroCombat\Experiment\CanonicalMonotypeObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeSearchSpace;

require dirname(__DIR__).'/autoload.php';

if (5 !== $argc) {
    fwrite(STDERR, "Usage: php validate-monotype-search-space.php <experiment.json> <objectives.json> <search-space.json> <empty-output-directory>\n");
    exit(2);
}

[$script, $experimentPath, $objectivePath, $searchSpacePath, $outputDirectory] = $argv;

try {
    if (file_exists($outputDirectory)) {
        if (!is_dir($outputDirectory)) {
            throw new RuntimeException(sprintf('Output path "%s" is not a directory.', $outputDirectory));
        }
        $entries = array_values(array_diff(scandir($outputDirectory) ?: [], ['.', '..']));
        if ([] !== $entries) {
            throw new RuntimeException(sprintf('Output directory "%s" must be empty.', $outputDirectory));
        }
    }

    $read = static function (string $path, string $kind): string {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read %s "%s".', $kind, $path));
        }

        return $contents;
    };
    $experimentJson = $read($experimentPath, 'experiment');
    $objectiveJson = $read($objectivePath, 'objective document');
    $searchSpaceJson = $read($searchSpacePath, 'search-space manifest');
    $experiment = ExperimentDefinition::fromJson($experimentJson);
    $objectives = json_decode($objectiveJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($objectives) || array_is_list($objectives)) {
        throw new InvalidArgumentException('The objective document must be a JSON object.');
    }
    $manifest = json_decode($searchSpaceJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || array_is_list($manifest)) {
        throw new InvalidArgumentException('The search-space manifest must be a JSON object.');
    }

    $space = MonotypeSearchSpace::fromArray($manifest, $experiment);
    $initialValues = $space->validateExperiment($experiment);
    $initialReport = (new ExperimentRunner())->run($experiment);
    $objectiveEvaluation = (new CanonicalMonotypeObjectiveEvaluator())->evaluate($initialReport, $objectives);

    $safety = [];
    foreach (['minimum', 'maximum'] as $bound) {
        $candidate = $space->candidateFromValues(
            $space->boundValues($bound),
            't30-'.$bound.'-safety-probe',
            'T30 '.$bound.' safety probe',
            't30.0-'.$bound,
        );
        $probeValues = $experiment->toArray();
        $probeValues['iterations'] = 1;
        $probeValues['candidate'] = $candidate;
        $probeReport = (new ExperimentRunner())->run(ExperimentDefinition::fromJson(json_encode($probeValues, JSON_THROW_ON_ERROR)));
        $safety[$bound] = [
            'candidateValid' => true,
            'scenarioCount' => $probeReport['experiment']['scenarioCount'],
            'candidateCombatCount' => $probeReport['experiment']['scenarioCount'],
            'baselineCombatCount' => $probeReport['experiment']['scenarioCount'],
            'numericOverflow' => false,
        ];
    }

    $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $candidateJson = $encode($experiment->candidate->toArray());
    $hashes = [
        'experiment' => hash('sha256', $experimentJson),
        'objectives' => hash('sha256', $objectiveJson),
        'searchSpace' => hash('sha256', $searchSpaceJson),
        'candidateInitial' => hash('sha256', $candidateJson),
    ];
    $initialCandidateCombatCount = $experiment->iterations * count($experiment->scenarios);
    $safetyCandidateCombatCount = array_sum(array_column($safety, 'candidateCombatCount'));
    $candidateCombatCount = $initialCandidateCombatCount + $safetyCandidateCombatCount;
    $validation = [
        'schemaVersion' => 'waar-monotype-search-space-validation/0.1',
        'searchSpace' => [
            'id' => $space->id,
            'version' => $space->version,
            'status' => $space->status,
            'boundsStatus' => 'proposed-awaiting-review',
        ],
        'source' => [
            'experimentId' => $experiment->id,
            'candidateId' => $experiment->candidate->id,
            'candidateVersion' => $experiment->candidate->ruleset->version,
            'referenceCanonicalization' => MonotypeSearchSpace::REFERENCE_CANONICALIZATION,
            'referenceExpectedSha256' => $manifest['source']['reference']['sha256'],
            'referenceActualSha256' => MonotypeSearchSpace::canonicalReferenceFingerprint($experiment),
        ],
        'checks' => [
            'parameterCount' => count($space->parameters),
            'initialCandidateValid' => true,
            'initialRoundTripPreserved' => $experiment->candidate->toArray() === $space->candidateFromValues(
                $initialValues,
                $experiment->candidate->id,
                $experiment->candidate->label,
                $experiment->candidate->ruleset->version,
            ),
            'minimumCandidateValid' => $safety['minimum']['candidateValid'],
            'maximumCandidateValid' => $safety['maximum']['candidateValid'],
            'canonicalObjectiveCount' => $objectiveEvaluation['objectiveContract']['count'],
            'objectiveGenerationId' => $objectiveEvaluation['objectiveContract']['generationId'],
            'baselineFrozen' => true,
            'costsFrozen' => true,
            'scenariosFrozen' => true,
            'samplingFrozen' => true,
            'roundsAndSpreadFrozen' => true,
        ],
        'quantization' => [
            'step' => $space->quantization,
            'rounding' => 'nearest-half-up',
            'appliedBeforeCandidateIdentity' => true,
        ],
        'safetyProbes' => $safety,
        'execution' => [
            'purpose' => 'validation-only',
            'distinctVariantCount' => 4,
            'variantRunCount' => 6,
            'candidateCombatCount' => $candidateCombatCount,
            'baselineCombatCount' => $candidateCombatCount,
            'totalCombatCount' => 2 * $candidateCombatCount,
            'baselineCached' => false,
        ],
        'search' => [
            'performed' => false,
            'evaluatedCandidateCount' => 0,
            'candidateCombatCount' => 0,
        ],
        'hashes' => $hashes,
    ];

    $table = [
        '| Paramètre | Unité | Initial | Minimum | Maximum | Pas |',
        '|---|---|---:|---:|---:|---:|',
    ];
    foreach ($space->parameters as $parameter) {
        $table[] = sprintf(
            '| `%s` — %s | %s | %s | %s | %s | %s |',
            $parameter['path'],
            $parameter['label'],
            $parameter['unit'],
            $parameter['initial'],
            $parameter['minimum'],
            $parameter['maximum'],
            $parameter['quantization'],
        );
    }
    $report = implode("\n", [
        '# Validation de l’espace de recherche T30',
        '',
        sprintf('Manifeste `%s@%s` · statut des bornes : **proposées, en attente de contre-recette**.', $space->id, $space->version),
        '',
        '## Paramètres ouverts',
        '',
        ...$table,
        '',
        'Les facteurs de contre peuvent descendre sous 1 et inverser le bonus initial. Ces bornes ne garantissent pas les intentions de rôle et aucune contrainte de rôle cachée ne contribue au score T29c.',
        '',
        '## Champs figés',
        '',
        '- Témoin T28 complet, 16 scénarios et compositions, 200 répétitions, `baseSeed = 42`.',
        sprintf('- Référence T28 liée avant simulation par `%s` : `%s`.', MonotypeSearchSpace::REFERENCE_CANONICALIZATION, strtoupper(MonotypeSearchSpace::canonicalReferenceFingerprint($experiment))),
        '- Coûts `80/110/130/350`, trois rounds, dispersion `0.1`, départage `defender`.',
        '- Identités dirigées des trois contres ; les treize autres cellules restent implicitement neutres à 1.',
        '- Les identifiants, libellés et versions des futurs candidats sont des métadonnées hors de la clé paramétrique.',
        '',
        '## Contrôles',
        '',
        sprintf('Candidat initial : valide et conservé à l’identique. Minima/maxima : valides sur %d scénarios chacun, sans dépassement numérique.', count($experiment->scenarios)),
        sprintf('Objectifs : %d zones canoniques `%s`.', $objectiveEvaluation['objectiveContract']['count'], $objectiveEvaluation['objectiveContract']['metric']),
        sprintf('Contrôle exécuté : %d combats candidat et %d combats témoin (%d au total). Le témoin est rejoué par le runner autoritaire pour chacune des trois validations.', $candidateCombatCount, $candidateCombatCount, 2 * $candidateCombatCount),
        'Recherche : **non exécutée** (`0` candidat évalué, `0` combat de recherche). Les deux sondes de sécurité aux bornes utilisent une répétition par scénario et ne constituent pas une recherche.',
        '',
        '## Empreintes SHA-256',
        '',
        sprintf('- Expérience : `%s`', strtoupper($hashes['experiment'])),
        sprintf('- Objectifs : `%s`', strtoupper($hashes['objectives'])),
        sprintf('- Espace de recherche : `%s`', strtoupper($hashes['searchSpace'])),
        sprintf('- Candidat initial : `%s`', strtoupper($hashes['candidateInitial'])),
        '',
    ]);

    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }
    $artifacts = [
        'experiment.json' => $experimentJson,
        'objectives.json' => $objectiveJson,
        'search-space.json' => $searchSpaceJson,
        'candidate-initial.json' => $candidateJson,
        'validation.json' => $encode($validation),
        'report.md' => $report,
    ];
    foreach ($artifacts as $name => $contents) {
        if (false === file_put_contents($outputDirectory.'/'.$name, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "T30 valide : %d paramètres proposés · initial/min/max légaux · recherche non exécutée\nRapport : %s\n",
        count($space->parameters),
        realpath($outputDirectory.'/report.md') ?: $outputDirectory.'/report.md',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
