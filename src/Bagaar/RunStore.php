<?php

namespace Waar\MicroCombat\Bagaar;

/** Local, isolated run persistence. The host game's database is never touched. */
final class RunStore
{
    public function __construct(private readonly string $directory = '')
    {
    }

    public function create(array $document): string
    {
        $directory = $this->directory();
        $id = bin2hex(random_bytes(16));
        $path = $directory.DIRECTORY_SEPARATOR.$id.'.json';
        $handle = fopen($path, 'x+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de créer la simulation Bagaar.');
        }
        try {
            $this->write($handle, $document);
        } finally {
            fclose($handle);
        }
        return $id;
    }

    public function read(string $id): array
    {
        return $this->withLocked($id, LOCK_SH, static fn (array $document): array => $document);
    }

    public function update(string $id, callable $change): array
    {
        return $this->withLocked($id, LOCK_EX, function (array $document, $handle) use ($change): array {
            $updated = $change($document);
            if (!is_array($updated)) {
                throw new \LogicException('État de simulation invalide.');
            }
            $this->write($handle, $updated);
            return $updated;
        });
    }

    private function withLocked(string $id, int $mode, callable $callback): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new \InvalidArgumentException('Identifiant de simulation invalide.');
        }
        $path = $this->directory().DIRECTORY_SEPARATOR.$id.'.json';
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Simulation introuvable.', 404);
        }
        try {
            if (!flock($handle, $mode)) {
                throw new \RuntimeException('Simulation temporairement indisponible.', 503);
            }
            $content = stream_get_contents($handle);
            $document = json_decode($content ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \RuntimeException('Simulation corrompue.');
            }
            return $callback($document, $handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function directory(): string
    {
        $directory = $this->directory !== '' ? $this->directory : sys_get_temp_dir().DIRECTORY_SEPARATOR.'waar-bagaar-runs';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire Bagaar inaccessible.');
        }
        return $directory;
    }

    private function write($handle, array $document): void
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new \RuntimeException('Impossible d’enregistrer la simulation Bagaar.');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Impossible d’enregistrer la simulation Bagaar.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new \RuntimeException('Impossible d’enregistrer la simulation Bagaar.');
        }
    }
}
