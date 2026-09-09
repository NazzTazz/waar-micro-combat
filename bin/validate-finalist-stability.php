<?php

use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\FinalistStabilityPlanBuilder;
use Waar\MicroCombat\Experiment\FinalistStabilityPresentationBuilder;
use Waar\MicroCombat\Experiment\FinalistStabilityRunner;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$t31Directory = $argv[1] ?? $projectRoot.'/var/waar-micro-combat/t31-standard-seed-314159';
$outputDirectory = $argv[2] ?? $projectRoot.'/var/waar-micro-combat/t33-finalist-stability';
$iterations = isset($argv[3]) ? (int) $argv[3] : 1000;
$baseSeeds = isset($argv[4]) ? array_map('intval', explode(',', $argv[4])) : [104729, 130363, 155921, 180749, 205759];

try {
    if ($argc > 5) {
        throw new InvalidArgumentException('Usage: php validate-finalist-stability.php [t31-run-directory] [empty-output-directory] [iterations] [comma-separated-base-seeds]');
    }
    if (is_dir($outputDirectory) && [] !== array_values(array_diff(scandir($outputDirectory) ?: [], ['.', '..']))) {
        throw new RuntimeException(sprintf('Output directory "%s" must be absent or empty.', $outputDirectory));
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create output directory "%s".', $outputDirectory));
    }
    $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $write = static function (string $path, string $contents): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
        if (false === file_put_contents($path, $contents)) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $path));
        }
    };

    $plan = (new FinalistStabilityPlanBuilder())->buildFromDirectory($t31Directory, $baseSeeds, $iterations);
    $copies = [
        'experiment' => 'experiment.json',
        'objectives' => 'objectives.json',
        'searchPlan' => 'search-plan.json',
        'searchResult' => 'search-result.json',
    ];
    foreach ($plan['candidates'] as $index => $candidate) {
        $copies['candidate-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)] = $candidate['sourcePath'];
    }
    $plan['frozenCopies'] = [];
    foreach ($copies as $key => $relativeSource) {
        $contents = file_get_contents(rtrim($t31Directory, '/\\').'/'.$relativeSource);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to copy frozen input "%s".', $relativeSource));
        }
        $destination = 'inputs/'.$key.'.json';
        $write($outputDirectory.'/'.$destination, $contents);
        $plan['frozenCopies'][$key] = ['path' => $destination, 'sha256' => hash('sha256', $contents)];
    }
    $planJson = $encode($plan);
    $write($outputDirectory.'/validation-plan.json', $planJson);
    $planSha256 = hash('sha256', $planJson);
    fwrite(STDERR, sprintf("T33 plan écrit avant mesure · SHA-256 %s · %d collision(s) corrigée(s)\n", $planSha256, count($plan['seedSeparation']['corrections'])));

    $startedAt = gmdate('c');
    $startNanoseconds = hrtime(true);
    $batchStarts = [];
    $batchDurations = [];
    $result = (new FinalistStabilityRunner())->run(
        $plan,
        $t31Directory,
        static function (string $event, int $batch, ?int $candidateOffset, int $candidateCount) use (&$batchStarts, &$batchDurations): void {
            if ('batch-start' === $event) {
                $batchStarts[$batch] = hrtime(true);
                fwrite(STDERR, sprintf("T33 lot %d démarré\n", $batch));
            } elseif ('candidate-complete' === $event) {
                fwrite(STDERR, sprintf("T33 lot %d · candidat %d/%d terminé\n", $batch, $candidateOffset, $candidateCount));
            } elseif ('batch-complete' === $event) {
                $batchDurations[$batch] = (hrtime(true) - $batchStarts[$batch]) / 1_000_000_000;
            }
        },
    );
    $result['planSha256'] = $planSha256;

    foreach ($result['candidates'] as $candidateIndex => $candidate) {
        $candidateDirectory = sprintf('%02d-%s', $candidateIndex, $candidate['id']);
        foreach ($candidate['batches'] as $batch) {
            $batchDirectory = sprintf('batches/%02d-seed-%d/%s', $batch['batch'], $batch['baseSeed'], $candidateDirectory);
            $write($outputDirectory.'/'.$batchDirectory.'/micro-report.json', $encode($batch['report']));
            $write($outputDirectory.'/'.$batchDirectory.'/evaluation.json', $encode($batch['evaluation']));
        }
        $write($outputDirectory.'/aggregates/'.$candidateDirectory.'/micro-report.json', $encode($candidate['aggregate']['report']));
        $write($outputDirectory.'/aggregates/'.$candidateDirectory.'/evaluation.json', $encode($candidate['aggregate']['evaluation']));
        $write($outputDirectory.'/aggregates/'.$candidateDirectory.'/variation-between-batches.json', $encode(['label' => 'variation entre lots', 'objectives' => $candidate['variationBetweenBatches']]));
    }

    $resultJson = $encode($result);
    $write($outputDirectory.'/result.json', $resultJson);
    $objectives = json_decode((string) file_get_contents($t31Directory.'/objectives.json'), true, 512, JSON_THROW_ON_ERROR);
    $presentation = (new FinalistStabilityPresentationBuilder())->build($result, $plan, $objectives, $planSha256);
    $presentationJson = $encode($presentation);
    $write($outputDirectory.'/presentation.json', $presentationJson);

    $resources = dirname(__DIR__).'/resources';
    $template = file_get_contents($resources.'/finalist-stability.html');
    $echarts = file_get_contents($resources.'/vendor/echarts-5.6.0.min.js');
    $model = file_get_contents($resources.'/finalist-stability-model.js');
    $application = file_get_contents($resources.'/finalist-stability-app.js');
    if (false === $template || false === $echarts || false === $model || false === $application) {
        throw new RuntimeException('Unable to read the embedded T33 presentation resources.');
    }
    $renderer = new AcceptanceOverlayRenderer();
    $write($outputDirectory.'/report.md', $renderer->finalistStabilityMarkdown($presentation, $result));
    $write($outputDirectory.'/report.html', $renderer->finalistComparisonHtml($presentation, $template, $echarts, $model, $application));

    $durationSeconds = (hrtime(true) - $startNanoseconds) / 1_000_000_000;
    $metadata = [
        'schemaVersion' => 'waar-monotype-finalist-stability-execution-metadata/0.1',
        'planId' => $plan['id'],
        'planSha256' => $planSha256,
        'startedAtUtc' => $startedAt,
        'finishedAtUtc' => gmdate('c'),
        'durationSeconds' => $durationSeconds,
        'batchDurationSeconds' => $batchDurations,
        'runtime' => ['phpVersion' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m')],
    ];
    $write($outputDirectory.'/execution-metadata.json', $encode($metadata));
    $manifest = [];
    foreach (['validation-plan.json', 'result.json', 'presentation.json', 'report.md', 'report.html', 'execution-metadata.json'] as $artifact) {
        $manifest[$artifact] = hash_file('sha256', $outputDirectory.'/'.$artifact);
    }
    $write($outputDirectory.'/artifact-sha256.json', $encode(['schemaVersion' => 'waar-t33-artifact-manifest/0.1', 'sha256' => $manifest]));

    fwrite(STDOUT, sprintf(
        "T33 terminée · %d lots · %s combats réels · %.3f s\nCandidats stables : %d/%d\nRapport : %s\n",
        $result['measurement']['batchCount'],
        number_format($result['execution']['actualCombatCount'], 0, ',', ' '),
        $durationSeconds,
        $result['summary']['stableCandidateCount'],
        $result['summary']['candidateCount'],
        realpath($outputDirectory.'/report.html') ?: $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : '.$exception->getMessage()."\n");
    exit(1);
}
