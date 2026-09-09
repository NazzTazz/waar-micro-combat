<?php

use Waar\MicroCombat\Experiment\MixedCompositionObservationPlanBuilder;
use Waar\MicroCombat\Experiment\MixedCompositionObservationPresentationBuilder;
use Waar\MicroCombat\Experiment\MixedCompositionObservationRenderer;
use Waar\MicroCombat\Experiment\MixedCompositionObservationRunner;

require dirname(__DIR__).'/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$t24Path = $argv[1] ?? $projectRoot.'/packages/waar-micro-combat/experiments/t24-astra-vector-corrections.json';
$t33Directory = $argv[2] ?? $projectRoot.'/var/waar-micro-combat/t33-finalist-stability';
$outputDirectory = $argv[3] ?? $projectRoot.'/var/waar-micro-combat/t34-mixed-composition-observation';
$iterations = isset($argv[4]) ? (int) $argv[4] : MixedCompositionObservationPlanBuilder::ITERATIONS;
$baseSeed = isset($argv[5]) ? (int) $argv[5] : MixedCompositionObservationPlanBuilder::BASE_SEED;

try {
    if ($argc > 6) {
        throw new InvalidArgumentException('Usage: php observe-mixed-compositions.php [t24-corpus] [t33-run-directory] [empty-output-directory] [iterations] [base-seed]');
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

    $plan = (new MixedCompositionObservationPlanBuilder())->build($t24Path, $t33Directory, $iterations, $baseSeed);
    $copies = ['t24-corpus.json' => $t24Path];
    foreach ($plan['variants'] as $index => $candidate) {
        $copies[sprintf('candidate-%02d.json', $index)] = $candidate['sourcePath'];
    }
    $copies['t33-validation-plan.json'] = rtrim($t33Directory, '/\\').'/validation-plan.json';
    $copies['t33-result.json'] = rtrim($t33Directory, '/\\').'/result.json';
    $plan['frozenCopies'] = [];
    foreach ($copies as $destination => $source) {
        $contents = file_get_contents($source);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to freeze "%s".', $source));
        }
        $relative = 'inputs/'.$destination;
        $write($outputDirectory.'/'.$relative, $contents);
        $plan['frozenCopies'][$destination] = ['path' => $relative, 'sha256' => hash('sha256', $contents)];
    }
    foreach ($plan['variants'] as $index => &$candidate) {
        $candidate['sourcePath'] = sprintf('inputs/candidate-%02d.json', $index);
    }
    unset($candidate);
    $plan['corpus']['sourcePath'] = 'inputs/t24-corpus.json';
    $planJson = $encode($plan);
    $write($outputDirectory.'/observation-plan.json', $planJson);
    $planSha256 = hash('sha256', $planJson);
    fwrite(STDERR, sprintf("T34 manifeste écrit avant mesure · SHA-256 %s · seed réservée %d\n", $planSha256, $baseSeed));

    $startedAt = gmdate('c');
    $startedNanoseconds = hrtime(true);
    $result = (new MixedCompositionObservationRunner())->run(
        $plan,
        $outputDirectory.'/inputs',
        static fn (int $index, int $count) => fwrite(STDERR, sprintf("T34 finaliste %d/%d terminé\n", $index, $count)),
    );
    $result['planSha256'] = $planSha256;
    $resultJson = $encode($result);
    $write($outputDirectory.'/result.json', $resultJson);

    $presentation = (new MixedCompositionObservationPresentationBuilder())->build($plan, $result, $planSha256);
    $presentationJson = $encode($presentation);
    $write($outputDirectory.'/presentation.json', $presentationJson);
    $resources = dirname(__DIR__).'/resources';
    $template = file_get_contents($resources.'/mixed-composition-observation.html');
    $echarts = file_get_contents($resources.'/vendor/echarts-5.6.0.min.js');
    $model = file_get_contents($resources.'/mixed-composition-observation-model.js');
    $application = file_get_contents($resources.'/mixed-composition-observation-app.js');
    if (false === $template || false === $echarts || false === $model || false === $application) {
        throw new RuntimeException('Unable to read T34 presentation resources.');
    }
    $renderer = new MixedCompositionObservationRenderer();
    $write($outputDirectory.'/report.md', $renderer->markdown($presentation));
    $write($outputDirectory.'/report.html', $renderer->html($presentation, $template, $echarts, $model, $application));

    $metadata = [
        'schemaVersion' => 'waar-mixed-composition-observation-execution-metadata/0.1',
        'planId' => $plan['id'],
        'planSha256' => $planSha256,
        'startedAtUtc' => $startedAt,
        'finishedAtUtc' => gmdate('c'),
        'durationSeconds' => (hrtime(true) - $startedNanoseconds) / 1_000_000_000,
        'runtime' => ['phpVersion' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m')],
        'manualRoute' => 'php packages/waar-micro-combat/bin/observe-mixed-compositions.php',
    ];
    $write($outputDirectory.'/execution-metadata.json', $encode($metadata));
    $artifacts = [];
    foreach (['observation-plan.json', 'result.json', 'presentation.json', 'report.md', 'report.html', 'execution-metadata.json'] as $artifact) {
        $artifacts[$artifact] = hash_file('sha256', $outputDirectory.'/'.$artifact);
    }
    $write($outputDirectory.'/artifact-sha256.json', $encode(['schemaVersion' => 'waar-t34-artifact-manifest/0.1', 'sha256' => $artifacts]));

    fwrite(STDOUT, sprintf(
        "T34 terminée · %s combats réels · %.3f s\nRenversements de majorité : %d\nRapport : %s\n",
        number_format($result['execution']['actualCombatCount'], 0, ',', ' '),
        $metadata['durationSeconds'],
        count($result['observations']['majorityWinnerReversals']),
        $outputDirectory.'/report.html',
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'T34 interrompue : '.$exception->getMessage()."\n");
    exit(1);
}
