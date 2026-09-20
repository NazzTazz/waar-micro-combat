<?php

require dirname(__DIR__).'/autoload.php';

use App\Game\Combat\CanonicalJson;
use App\Game\Combat\CombatEngine;
use App\Game\Combat\CombatReplay;

try {
    if ($argc !== 2) throw new InvalidArgumentException('Usage: php replay.php report.json');
    $json = file_get_contents($argv[1]);
    if ($json === false) throw new RuntimeException('Cannot read the report.');
    $report = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json), true, 512, JSON_THROW_ON_ERROR);
    // The standalone demo wraps the combat report alongside its batch measurements.
    $report = $report['combat'] ?? $report;
    $replayed = (new CombatEngine())->resolveRequest(CombatReplay::request($report));
    if (CanonicalJson::encode($replayed) !== CanonicalJson::encode($report)) {
        throw new RuntimeException('Replay differs from the saved report.');
    }
    echo json_encode($replayed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
