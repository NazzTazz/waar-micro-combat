<?php

/** Diagnostic worker: keep PHP alive while its normal runtime opens Rust per request. */
require dirname(__DIR__).'/autoload.php';

use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

$runtime = new ProcessCohortRuntime();
while (($line = fgets(STDIN)) !== false) {
    try {
        $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $result = $runtime->batch($request);
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
    flush();
}
