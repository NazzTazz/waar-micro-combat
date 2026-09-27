<?php

namespace Waar\MicroCombat\Bagaar;

use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

/** Reuses the native JSONL process for all combats in one Bagaar step request. */
final class StreamingCohortRuntime implements CohortRuntime
{
    private $process = null;
    private array $pipes = [];

    public function resolve(array $request): array
    {
        $this->open();
        $payload = json_encode(['operation' => 'resolve', 'request' => $request], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($this->pipes[0], substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Transmission au moteur Rust interrompue.');
            }
            $offset += $written;
        }
        fflush($this->pipes[0]);
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('Moteur Rust interrompu avant le résultat.');
        }
        $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || isset($result['error'])) {
            throw new \RuntimeException('Moteur Rust : '.(string)($result['error'] ?? 'réponse invalide'));
        }
        $document = $result['result'] ?? null;
        if (!is_array($document) || ($document['schemaVersion'] ?? null) !== 'waar-combat-result/2'
            || ($document['modelVersion'] ?? null) !== ProcessCohortRuntime::MODEL_VERSION) {
            throw new \RuntimeException('Version de moteur Rust incompatible.');
        }
        return $result;
    }

    public function batch(array $request): array
    {
        throw new \LogicException('Bagaar résout les combats dans l’ordre des actions.');
    }

    public function provenance(): array
    {
        return ['kind' => 'rust', 'transport' => 'persistent-process-jsonl', 'modelVersion' => ProcessCohortRuntime::MODEL_VERSION];
    }

    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        stream_get_contents($this->pipes[2]);
        fclose($this->pipes[2]);
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    private function open(): void
    {
        if (is_resource($this->process)) {
            return;
        }
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $binary = dirname(__DIR__, 2).'/engines/waar-cohort/rust/target/release/waar-cohort-cli'.$suffix;
        if (!is_file($binary)) {
            throw new \RuntimeException('Moteur Rust introuvable. Compilez le runtime cohortes.');
        }
        $this->process = proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, dirname(__DIR__, 2));
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Impossible de démarrer le moteur Rust.');
        }
    }
}
