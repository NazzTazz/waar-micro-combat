<?php

use Waar\MicroCombat\Experiment\BoundedMonotypeCandidateSearch;
use Waar\MicroCombat\Experiment\CanonicalMonotypeSearchObjectiveEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\MonotypeSearchSpace;

require dirname(__DIR__).'/autoload.php';

if ($argc < 7 || $argc > 8) {
    fwrite(STDERR, "Usage: php search-monotype-candidates.php <experiment.json> <objectives.json> <search-space.json> <search-seed> <evaluation-budget> <empty-output-directory> [proposal-limit]\n");
    exit(2);
}

[$script, $experimentPath, $objectivePath, $searchSpacePath, $seedText, $budgetText, $outputDirectory] = $argv;
$proposalLimitText = $argv[7] ?? null;

try {
    $integer = static function (string $value, string $name): int {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s must be a non-negative integer.', $name));
        }

        return (int) $value;
    };
    $searchSeed = $integer($seedText, 'Search seed');
    $evaluationBudget = $integer($budgetText, 'Evaluation budget');
    $proposalLimit = null === $proposalLimitText ? null : $integer($proposalLimitText, 'Proposal limit');

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
    $reference = ExperimentDefinition::fromJson($experimentJson);
    $objectives = json_decode($objectiveJson, true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode($searchSpaceJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($objectives) || array_is_list($objectives)) {
        throw new InvalidArgumentException('The objective document must be a JSON object.');
    }
    if (!is_array($manifest) || array_is_list($manifest)) {
        throw new InvalidArgumentException('The search-space manifest must be a JSON object.');
    }
    $space = MonotypeSearchSpace::fromArray($manifest, $reference);

    $encode = static fn (array $value): string => json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";
    $compact = static fn (array $value): string => json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $candidateInitialJson = $encode($reference->candidate->toArray());
    $inputHashes = [
        'experiment' => hash('sha256', $experimentJson),
        'objectives' => hash('sha256', $objectiveJson),
        'searchSpace' => hash('sha256', $searchSpaceJson),
        'candidateInitial' => hash('sha256', $candidateInitialJson),
    ];
    $baselineCacheContract = [
        'variant' => $reference->baseline->toArray(),
        'iterations' => $reference->iterations,
        'baseSeed' => $reference->baseSeed,
        'scenarios' => array_map(static fn ($scenario): array => $scenario->toArray(), $reference->scenarios),
    ];
    $effectiveProposalLimit = $proposalLimit ?? 10 * $evaluationBudget;
    $profile = 8 === $evaluationBudget ? 'smoke' : (128 === $evaluationBudget ? 'standard' : 'custom');
    $searchPlan = [
        'schemaVersion' => 'waar-monotype-search-plan/0.1',
        'id' => sprintf('t31-%s-seed-%d-budget-%d', $profile, $searchSeed, $evaluationBudget),
        'slice' => 'T31',
        'profile' => $profile,
        'inputs' => [
            'experimentId' => $reference->id,
            'objectiveGenerationId' => $objectives['generation']['id'] ?? null,
            'searchSpaceId' => $space->id,
            'searchSpaceVersion' => $space->version,
            'searchSpaceBoundsDecision' => 'accepted-for-t31-by-counter-review',
            'sha256' => $inputHashes,
        ],
        'sampling' => [
            'iterationsPerScenario' => $reference->iterations,
            'combatBaseSeed' => $reference->baseSeed,
            'pairedCombatSeeds' => true,
            'searchSeed' => $searchSeed,
        ],
        'limits' => [
            'uniqueEvaluationBudget' => $evaluationBudget,
            'proposalLimit' => $effectiveProposalLimit,
        ],
        'strategy' => [
            'initialCandidateFirst' => true,
            'globalUniqueTarget' => intdiv($evaluationBudget, 2),
            'globalSampling' => 'For each parameter, one Lcg31 state modulo the inclusive quantized grid size.',
            'localSampling' => 'From the current best, one Lcg31 state per parameter reduced modulo an offset width of twice ceil(10% of the grid span) plus one, then clamp.',
            'deduplication' => 'After quantization, SHA-256 of the ordered 15-value JSON object; candidate metadata excluded.',
            'ranking' => 'T29c continuous loss ascending, then canonical parameter SHA-256 ascending on exact ties.',
            'stop' => 'Unique evaluation budget or proposal limit, whichever occurs first.',
        ],
        'baselineCache' => [
            'enabled' => true,
            'keyCanonicalization' => 'compact-json-v1',
            'keySha256' => hash('sha256', $compact($baselineCacheContract)),
            'includes' => ['baselineVariant', 'iterations', 'baseSeed', 'orderedScenarios'],
        ],
        'strictAcceptance' => '32/32 objectives and zero draws',
    ];

    $runner = new ExperimentRunner();
    $objectiveEvaluator = new CanonicalMonotypeSearchObjectiveEvaluator();
    $baselineReport = null;
    $startNanoseconds = hrtime(true);
    $startedAt = gmdate('c');
    $timedProgress = [];
    $search = (new BoundedMonotypeCandidateSearch())->search(
        $reference,
        $space,
        $searchSeed,
        $evaluationBudget,
        $proposalLimit,
        static function (array $candidate, array $parameters, int $sequence) use (
            $reference,
            $runner,
            $objectiveEvaluator,
            $objectives,
            &$baselineReport,
        ): array {
            $values = $reference->toArray();
            $values['candidate'] = $candidate;
            $experiment = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));
            $report = 1 === $sequence
                ? $runner->run($experiment)
                : $runner->runWithBaselineReport($experiment, $baselineReport);
            if (1 === $sequence) {
                $baselineReport = $report;
            }

            return $objectiveEvaluator->evaluate($report, $objectives);
        },
        static function (array $progress) use (&$timedProgress, $startNanoseconds): void {
            $elapsed = (hrtime(true) - $startNanoseconds) / 1_000_000_000;
            $timedProgress[] = $progress + ['elapsedSeconds' => $elapsed];
            fwrite(STDERR, sprintf(
                "T31 %d évalués · meilleure perte %.12f · %d/32 objectifs · pire %.12f · %.1fs\n",
                $progress['evaluatedCandidateCount'],
                $progress['bestLoss'],
                $progress['bestObjectivesSatisfied'],
                $progress['bestWorstExcess'],
                $elapsed,
            ));
        },
    );

    $summary = static fn (array $record): array => [
        'sequence' => $record['sequence'],
        'proposalNumber' => $record['proposalNumber'],
        'phase' => $record['phase'],
        'candidateId' => $record['candidate']['id'],
        'candidateVersion' => $record['candidate']['version'],
        'parameterFingerprint' => $record['parameterFingerprint'],
        'summary' => $record['summary'],
    ];
    $finalistArtifacts = [];
    $finalistFiles = [];
    foreach ($search['finalists'] as $offset => $finalist) {
        $rank = $offset + 1;
        $directory = sprintf('finalists/%02d-%s', $rank, $finalist['candidate']['id']);
        $values = $reference->toArray();
        $values['candidate'] = $finalist['candidate'];
        $experiment = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));
        $replayReport = $runner->run($experiment);
        $replayEvaluation = $objectiveEvaluator->evaluate($replayReport, $objectives);
        if ($replayEvaluation !== $finalist['evaluation']) {
            throw new RuntimeException(sprintf('Authoritative replay differs for finalist "%s".', $finalist['candidate']['id']));
        }
        $variantJson = $encode($finalist['candidate']);
        $evaluationJson = $encode($replayEvaluation);
        $reportJson = $encode($replayReport);
        $finalistFiles[$directory.'/variant.json'] = $variantJson;
        $finalistFiles[$directory.'/evaluation.json'] = $evaluationJson;
        $finalistFiles[$directory.'/micro-report.json'] = $reportJson;
        $finalistArtifacts[] = $summary($finalist) + [
            'rank' => $rank,
            'artifacts' => [
                'variant' => $directory.'/variant.json',
                'evaluation' => $directory.'/evaluation.json',
                'microReport' => $directory.'/micro-report.json',
            ],
            'sha256' => [
                'variant' => hash('sha256', $variantJson),
                'evaluation' => hash('sha256', $evaluationJson),
                'microReport' => hash('sha256', $reportJson),
            ],
            'authoritativeReplayMatched' => true,
        ];
    }

    $scenarioCombats = count($reference->scenarios) * $reference->iterations;
    $result = [
        'schemaVersion' => 'waar-monotype-search-result/0.1',
        'planId' => $searchPlan['id'],
        'state' => $search['state'],
        'outcome' => $search['outcome'],
        'counts' => [
            'evaluationBudget' => $search['evaluationBudget'],
            'evaluatedCandidates' => $search['evaluatedCandidateCount'],
            'proposalLimit' => $search['proposalLimit'],
            'proposals' => $search['proposalCount'],
            'duplicateProposals' => $search['duplicateProposalCount'],
            'globalCandidates' => $search['globalCandidateCount'],
            'localCandidates' => $search['localCandidateCount'],
            'strictCandidates' => count($search['strictCandidates']),
            'finalists' => count($search['finalists']),
        ],
        'execution' => [
            'searchCandidateCombatCount' => $search['evaluatedCandidateCount'] * $scenarioCombats,
            'searchBaselineCombatCount' => $scenarioCombats,
            'baselineCacheHits' => max(0, $search['evaluatedCandidateCount'] - 1),
            'finalistReplayCandidateCombatCount' => count($search['finalists']) * $scenarioCombats,
            'finalistReplayBaselineCombatCount' => count($search['finalists']) * $scenarioCombats,
            'totalCombatCount' => ($search['evaluatedCandidateCount'] + 1 + 2 * count($search['finalists'])) * $scenarioCombats,
        ],
        'inputSha256' => $inputHashes,
        'strategy' => $search['strategy'],
        'initial' => $summary($search['initial']),
        'best' => $summary($search['best']),
        'progression' => $search['progression'],
        'finalists' => $finalistArtifacts,
        'strictCandidates' => array_map($summary, $search['strictCandidates']),
        'invariantDiagnostics' => $search['invariantDiagnostics'],
    ];
    $evaluationLines = array_map($compact, $search['evaluations']);
    $evaluationsJsonl = implode("\n", $evaluationLines)."\n";
    $durationSeconds = (hrtime(true) - $startNanoseconds) / 1_000_000_000;
    $executionMetadata = [
        'schemaVersion' => 'waar-monotype-search-execution-metadata/0.1',
        'planId' => $searchPlan['id'],
        'startedAtUtc' => $startedAt,
        'finishedAtUtc' => gmdate('c'),
        'durationSeconds' => $durationSeconds,
        'runtime' => [
            'phpVersion' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'machine' => php_uname('n'),
        ],
        'progressionTiming' => $timedProgress,
        'excludedFromDeterministicResultHashes' => true,
    ];
    $best = $result['best'];
    $finalistTable = [
        '| Rang | Candidat | Perte | Objectifs | Pire excès | Nuls | Statut |',
        '|---:|---|---:|---:|---:|---:|---|',
    ];
    foreach ($result['finalists'] as $finalist) {
        $finalistTable[] = sprintf(
            '| %d | `%s` | %.12f | %d/32 | %.12f | %d | `%s` |',
            $finalist['rank'],
            $finalist['candidateId'],
            $finalist['summary']['continuousLoss'],
            $finalist['summary']['objectivesSatisfied'],
            $finalist['summary']['worstExcess'],
            $finalist['summary']['drawCount'],
            $finalist['summary']['classification'],
        );
    }
    $report = implode("\n", [
        '# Recherche bornée T31',
        '',
        sprintf('Plan `%s` · état **%s** · résultat **%s**.', $searchPlan['id'], $result['state'], $result['outcome']),
        '',
        sprintf('Évaluations : **%d/%d** candidats uniques après **%d/%d** propositions (%d doublons).', $result['counts']['evaluatedCandidates'], $result['counts']['evaluationBudget'], $result['counts']['proposals'], $result['counts']['proposalLimit'], $result['counts']['duplicateProposals']),
        sprintf('Meilleur : `%s` · perte `%.12f` · objectifs `%d/32` · pire excès `%.12f` sur `%s` · nuls `%d`.', $best['candidateId'], $best['summary']['continuousLoss'], $best['summary']['objectivesSatisfied'], $best['summary']['worstExcess'], $best['summary']['worstObjectiveId'], $best['summary']['drawCount']),
        sprintf('Initial : perte `%.12f` · objectifs `%d/32` · nuls `%d`.', $result['initial']['summary']['continuousLoss'], $result['initial']['summary']['objectivesSatisfied'], $result['initial']['summary']['drawCount']),
        '',
        sprintf('Finalistes sans nul : **%d**. Candidats strictement satisfaisants : **%d**.', $result['counts']['finalists'], $result['counts']['strictCandidates']),
        'Un résultat sans candidat strict signifie seulement qu’aucun candidat satisfaisant n’a été trouvé dans ce budget.',
        '',
        '## Finalistes',
        '',
        ...$finalistTable,
        '',
        '## Reproductibilité et coût',
        '',
        sprintf('Seed de recherche `%d`, seed de combat `%d`, `%d` répétitions par scénario. Classement : perte T29c croissante puis empreinte canonique croissante.', $searchSeed, $reference->baseSeed, $reference->iterations),
        sprintf('Combats de recherche : %d candidat et %d témoin mis en cache. Rejeu autoritaire des finalistes : %d candidat et %d témoin. Total réellement exécuté : %d.', $result['execution']['searchCandidateCombatCount'], $result['execution']['searchBaselineCombatCount'], $result['execution']['finalistReplayCandidateCombatCount'], $result['execution']['finalistReplayBaselineCombatCount'], $result['execution']['totalCombatCount']),
        'Les dates, la durée, la machine et les temps de progression sont isolés dans `execution-metadata.json`.',
        '',
        '## Entrées SHA-256',
        '',
        sprintf('- Expérience : `%s`', strtoupper($inputHashes['experiment'])),
        sprintf('- Objectifs : `%s`', strtoupper($inputHashes['objectives'])),
        sprintf('- Espace : `%s`', strtoupper($inputHashes['searchSpace'])),
        sprintf('- Candidat initial : `%s`', strtoupper($inputHashes['candidateInitial'])),
        '',
    ]);

    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }
    $artifacts = [
        'experiment.json' => $experimentJson,
        'objectives.json' => $objectiveJson,
        'search-space.json' => $searchSpaceJson,
        'candidate-initial.json' => $candidateInitialJson,
        'evaluation-initial.json' => $encode($search['initial']['evaluation']),
        'search-plan.json' => $encode($searchPlan),
        'search-result.json' => $encode($result),
        'evaluations.jsonl' => $evaluationsJsonl,
        'execution-metadata.json' => $encode($executionMetadata),
        'report.md' => $report,
        ...$finalistFiles,
    ];
    foreach ($artifacts as $name => $contents) {
        $path = $outputDirectory.'/'.$name;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create artifact directory "%s".', $directory));
        }
        if (false === file_put_contents($path, $contents)) {
            throw new RuntimeException(sprintf('Unable to write artifact "%s".', $name));
        }
    }

    fwrite(STDOUT, sprintf(
        "T31 %s : %d/%d candidats uniques · meilleure perte %.12f · %d/32 objectifs · %d finalistes\nRapport : %s\n",
        $result['state'],
        $result['counts']['evaluatedCandidates'],
        $result['counts']['evaluationBudget'],
        $best['summary']['continuousLoss'],
        $best['summary']['objectivesSatisfied'],
        $result['counts']['finalists'],
        realpath($outputDirectory.'/report.md') ?: $outputDirectory.'/report.md',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
