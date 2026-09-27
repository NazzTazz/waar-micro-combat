<?php

namespace Waar\MicroCombat\Workshop;

/**
 * Process boundary for the isolated cohort engine. A batch is one native call,
 * regardless of its number of scenarios or repetitions.
 */
final class ProcessCohortRuntime implements CohortRuntime
{
    public const MODEL_VERSION = 'waar-cohort-v2';

    /** @param null|list<string> $command */
    public function __construct(
        private readonly ?array $command = null,
        private readonly ?string $kind = null,
        private readonly bool $campaignRanges = false,
    ) {
    }

    public function resolve(array $request): array
    {
        return $this->call('resolve', $request, 'waar-combat-result/2');
    }

    public function batch(array $request): array
    {
        return $this->call($this->campaignRanges ? 'campaignBatch' : 'batch', $request, 'waar-combat-batch-result/2');
    }

    public function provenance(): array
    {
        return ['kind' => $this->runtimeKind(), 'transport' => 'process-jsonl', 'modelVersion' => self::MODEL_VERSION];
    }

    /** @return array<string,mixed> */
    private function call(string $operation, array $request, string $expectedSchema): array
    {
        $b1Diagnostic = getenv('WAAR_B1_DIAGNOSTIC') === '1';
        if ($b1Diagnostic) {
            $b1Started = hrtime(true);
        }
        $command = $this->resolvedCommand();
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, self::root());
        if (!is_resource($process)) {
            throw new \RuntimeException('Impossible de démarrer le moteur cohortes.');
        }
        if ($b1Diagnostic) {
            $b1Opened = hrtime(true);
        }
        try {
            $payload = json_encode(['operation' => $operation, 'request' => $request], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
            if ($b1Diagnostic) {
                $b1Encoded = hrtime(true);
            }
            if (false === fwrite($pipes[0], $payload)) {
                throw new \RuntimeException('Impossible de transmettre le travail au moteur cohortes.');
            }
            fclose($pipes[0]);
            if ($b1Diagnostic) {
                $b1Written = hrtime(true);
            }
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $process = null;
            if ($b1Diagnostic) {
                $b1Closed = hrtime(true);
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
            }
        }
        if ($status !== 0 || !is_string($stdout) || trim($stdout) === '') {
            $detail = is_string($stderr) && trim($stderr) !== '' ? trim($stderr) : 'aucune sortie exploitable';
            throw new \RuntimeException("Échec du moteur cohortes ({$this->runtimeKind()}) : {$detail}");
        }
        try {
            $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Le moteur cohortes a renvoyé un document JSON invalide.', 0, $error);
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new \RuntimeException('Réponse objet attendue du moteur cohortes.');
        }
        if (isset($result['error'])) {
            throw new \InvalidArgumentException('Moteur cohortes : '.(string)$result['error']);
        }

        $document = $operation === 'resolve' ? ($result['result'] ?? null) : $result;
        if (!is_array($document) || ($document['schemaVersion'] ?? null) !== $expectedSchema || ($document['modelVersion'] ?? null) !== self::MODEL_VERSION) {
            throw new \RuntimeException('Version de moteur cohortes absente ou incompatible.');
        }
        if ($b1Diagnostic) {
            error_log('B1_PROCESS '.json_encode([
                'requestId' => $_SERVER['WAAR_B1_REQUEST_ID'] ?? null,
                'operation' => $operation,
                'combats' => ($request['iterations'] ?? 1) * (isset($request['scenarios']) ? count($request['scenarios']) : 1),
                'spawnMs' => ($b1Opened - $b1Started) / 1_000_000,
                'encodeMs' => ($b1Encoded - $b1Opened) / 1_000_000,
                'writeMs' => ($b1Written - $b1Encoded) / 1_000_000,
                'readAndWaitMs' => ($b1Closed - $b1Written) / 1_000_000,
                'decodeAndCheckMs' => (hrtime(true) - $b1Closed) / 1_000_000,
                'requestBytes' => strlen($payload),
                'responseBytes' => strlen($stdout),
            ], JSON_THROW_ON_ERROR));
        }
        return $result;
    }

    /** @return list<string> */
    private function resolvedCommand(): array
    {
        if ($this->command !== null) {
            return $this->command;
        }
        if ($this->runtimeKind() === 'php') {
            $script = self::root().'/engines/waar-cohort/bin/php-runtime.php';
            if (!is_file($script)) {
                throw new \RuntimeException("Runtime PHP cohortes introuvable : {$script}");
            }
            return [PHP_BINARY, $script];
        }
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $binary = self::root().'/engines/waar-cohort/rust/target/release/waar-cohort-cli'.$suffix;
        if (!is_file($binary)) {
            throw new \RuntimeException("Runtime Rust cohortes introuvable : {$binary}. Exécutez engines/waar-cohort/bin/verify.ps1.");
        }
        return [$binary];
    }

    private function runtimeKind(): string
    {
        $kind = $this->kind ?? ($_SERVER['WAAR_COHORT_RUNTIME'] ?? $_ENV['WAAR_COHORT_RUNTIME'] ?? 'rust');
        if (!in_array($kind, ['rust', 'php'], true)) {
            throw new \InvalidArgumentException('WAAR_COHORT_RUNTIME doit valoir rust ou php.');
        }
        return $kind;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
