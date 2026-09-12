<?php

// Explicit local research dependency on the sibling Legacy oracle, not package runtime.
require dirname(__DIR__).'/autoload.php';
$root = dirname(__DIR__);
$output = $argv[1] ?? $root.'/reports/legacy-monotypes-116';
try {
    if ($argc > 2 || file_exists($output)) throw new RuntimeException('Output directory must be absent.');
    $host = realpath($root.'/../waar-v3');
    if (!$host) throw new RuntimeException('Sibling Legacy oracle unavailable.');
    spl_autoload_register(static function(string $class) use ($host): void {
        if (str_starts_with($class, 'App\\Game\\')) {
            $file = $host.'/src/'.str_replace('\\','/',substr($class,4)).'.php';
            if (is_file($file)) require $file;
        }
    });
    $read = static fn(string $path): array => json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $encode = static fn(array $data): string => json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $write = static function (string $path, string $contents): void {
        if (false === file_put_contents($path, $contents)) {
            throw new RuntimeException('Unable to write '.$path);
        }
    };
    $reference = $read($root.'/experiments/references/t25a1/legacy-reference.json');
    foreach ($reference['reference']['sourceFiles'] as $source) {
        if (hash_file('sha256',$host.'/'.$source['path']) !== $source['sha256']) throw new RuntimeException('Legacy source changed: '.$source['path']);
    }
    $exporter = new App\Game\Combat\Research\LegacyReferenceExporter($host);
    $preflight = $exporter->export($read($root.'/experiments/t24-astra-vector-corrections.json'));
    if ($preflight['rows'] !== $reference['rows']) throw new RuntimeException('Legacy T24 reproduction failed.');
    $experimentPath = $root.'/experiments/references/t31-standard-seed-314159/experiment.json';
    $experiment = $read($experimentPath);
    if (count($experiment['scenarios']) !== 16 || $experiment['iterations'] !== 200 || $experiment['baseSeed'] !== 42) throw new RuntimeException('Unexpected sampling.');
    if (!mkdir($output,0777,true)) throw new RuntimeException('Unable to create output directory.');
    $plan = ['schemaVersion'=>'waar-legacy-monotype-observation-plan/0.1','experiment'=>$experiment,'experimentSha256'=>hash_file('sha256',$experimentPath),'legacySources'=>$reference['reference']['sourceFiles'],'combats'=>3200,'preflightCombats'=>1200,'crossEnginePairingClaimed'=>false];
    $write($output.'/plan.json',$encode($plan));
    $result = $exporter->export($experiment);
    foreach ($reference['reference']['sourceFiles'] as $source) {
        if (hash_file('sha256',$host.'/'.$source['path']) !== $source['sha256']) throw new RuntimeException('Legacy source changed during observation.');
    }
    $write($output.'/legacy-reference.json',$encode($result));
    $write($output.'/manifest.json',$encode(['plan.json'=>hash_file('sha256',$output.'/plan.json'),'legacy-reference.json'=>hash_file('sha256',$output.'/legacy-reference.json')]));
    echo $output.'/legacy-reference.json'.PHP_EOL.'32 Legacy observations; 3200 combats, plus 1200 preflight combats.'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
