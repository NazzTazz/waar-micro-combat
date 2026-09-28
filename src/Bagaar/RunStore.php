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
        return $this->withLocked($id, LOCK_EX, function (array $document, $handle) use ($change, $id): array {
            $updated = $change($document);
            if (!is_array($updated)) {
                throw new \LogicException('État de simulation invalide.');
            }
            if (($updated['state']['traceDetached'] ?? false) === true) {
                $batches = ['frames' => $updated['state']['frames'], 'events' => $updated['state']['events'],
                    'combats' => $updated['state']['combats']];
                $stored = $updated;
                $stored['state']['frameCount'] = ($stored['state']['frameCount'] ?? 0) + count($batches['frames']);
                $stored['state']['eventCount'] = ($stored['state']['eventCount'] ?? 0) + count($batches['events']);
                $stored['state']['frames'] = $stored['state']['events'] = $stored['state']['combats'] = [];
                $this->appendDetachedAndWrite($id, $handle, $stored, $batches);
                $updated['state']['frameCount'] = $stored['state']['frameCount'];
                $updated['state']['eventCount'] = $stored['state']['eventCount'];
                return $updated;
            }
            if (($updated['state']['archiveDetached'] ?? false) === true) {
                $batch = $updated['state']['combats'];
                $updated['state']['combats'] = [];
                $this->appendAndWrite($id, $handle, $updated, $batch);
            } else {
                $this->write($handle, $updated);
            }
            return $updated;
        });
    }

    public function readTrace(string $id, string $kind, int $offset, int $limit): array
    {
        if (!in_array($kind, ['frames', 'events'], true) || $offset < 0 || $limit < 0) {
            throw new \InvalidArgumentException('Page de trace invalide.');
        }
        return $this->withLocked($id, LOCK_SH, function (array $document) use ($id, $kind, $offset, $limit): array {
            if (($document['state']['traceDetached'] ?? false) !== true) {
                return array_slice($document['state'][$kind], $offset, $limit);
            }
            $count = $document['state'][$kind === 'frames' ? 'frameCount' : 'eventCount'];
            if ($offset + $limit > $count) {
                throw new \InvalidArgumentException('Page de trace hors limites.');
            }
            if ($limit === 0) {
                return [];
            }
            $stream = @fopen($this->tracePath($id, $kind), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Archive de trace introuvable.');
            }
            try {
                for ($i = 0; $i < $offset; $i++) {
                    if (fgets($stream) === false) {
                        throw new \RuntimeException('Archive de trace incomplète.');
                    }
                }
                $page = [];
                for ($i = 0; $i < $limit; $i++) {
                    $line = fgets($stream);
                    if ($line === false) {
                        throw new \RuntimeException('Archive de trace incomplète.');
                    }
                    $page[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
                return $page;
            } finally {
                fclose($stream);
            }
        });
    }

    /** One-time migration, run from the CLI with a raised memory limit for large legacy JSON. */
    public function migrateTrace(string $id): array
    {
        return $this->withLocked($id, LOCK_EX, function (array $document, $handle) use ($id): array {
            if (($document['state']['traceDetached'] ?? false) === true) {
                return ['frames' => $document['state']['frameCount'], 'events' => $document['state']['eventCount']];
            }
            $frames = $document['state']['frames'];
            $events = $document['state']['events'];
            $recent = [];
            foreach ($events as $event) {
                if (($event['type'] ?? null) !== 'combat') {
                    continue;
                }
                $observation = array_intersect_key($event, array_flip(['tick', 'attacker', 'defender', 'winner', 'surrender', 'report']));
                foreach ([$event['attacker'], $event['defender']] as $playerId) {
                    if (isset($document['state']['players'][$playerId])) {
                        $recent[$playerId][] = $observation;
                        $recent[$playerId] = array_slice($recent[$playerId], -20);
                    }
                }
            }
            $document['state']['traceDetached'] = true;
            $document['state']['frameCount'] = count($frames);
            $document['state']['eventCount'] = count($events);
            $document['state']['observationEvents'] = $recent;
            $document['state']['frames'] = $document['state']['events'] = [];
            $this->appendDetachedAndWrite($id, $handle, $document,
                ['frames' => $frames, 'events' => $events, 'combats' => []]);
            return ['frames' => count($frames), 'events' => count($events)];
        });
    }

    public function readCombat(string $id, int $index): array
    {
        return $this->withLocked($id, LOCK_SH, function (array $document) use ($id, $index): array {
            $state = $document['state'];
            if (($state['archiveDetached'] ?? false) !== true) {
                return $state['combats'][$index] ?? throw new \RuntimeException('Combat introuvable.', 404);
            }
            if ($index >= ($state['combatCount'] ?? 0)) {
                throw new \RuntimeException('Combat introuvable.', 404);
            }
            $archive = @fopen($this->archivePath($id), 'rb');
            if ($archive === false) {
                throw new \RuntimeException('Archive de combats introuvable.', 404);
            }
            try {
                for ($position = 0; ($line = fgets($archive)) !== false; $position++) {
                    if ($position === $index) {
                        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            } finally {
                fclose($archive);
            }
            throw new \RuntimeException('Combat introuvable.', 404);
        });
    }

    /** Stream the original export shape without materializing every archived combat. */
    public function outputExport(string $id): void
    {
        $this->withLocked($id, LOCK_SH, function (array $document) use ($id): array {
            echo '{';
            $firstRoot = true;
            foreach ($document as $key => $value) {
                if (!$firstRoot) {
                    echo ',';
                }
                $firstRoot = false;
                echo json_encode((string) $key, JSON_THROW_ON_ERROR), ':';
                if ($key !== 'state') {
                    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    continue;
                }
                echo '{';
                $firstState = true;
                foreach ($value as $stateKey => $stateValue) {
                    if (!$firstState) {
                        echo ',';
                    }
                    $firstState = false;
                    echo json_encode((string) $stateKey, JSON_THROW_ON_ERROR), ':';
                    if (($value['traceDetached'] ?? false) === true && in_array($stateKey, ['frames', 'events'], true)) {
                        $this->outputJsonl($this->tracePath($id, $stateKey),
                            $value[$stateKey === 'frames' ? 'frameCount' : 'eventCount']);
                        continue;
                    }
                    if ($stateKey !== 'combats') {
                        echo json_encode($stateValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        continue;
                    }
                    echo '[';
                    $firstCombat = true;
                    if (($value['archiveDetached'] ?? false) === true) {
                        $archive = @fopen($this->archivePath($id), 'rb');
                        if ($archive !== false) {
                            try {
                                $remaining = $value['combatCount'] ?? 0;
                                while ($remaining-- > 0 && ($line = fgets($archive)) !== false) {
                                    if (!$firstCombat) {
                                        echo ',';
                                    }
                                    $firstCombat = false;
                                    echo trim($line);
                                }
                            } finally {
                                fclose($archive);
                            }
                        }
                    } else {
                        foreach ($stateValue as $combat) {
                            if (!$firstCombat) {
                                echo ',';
                            }
                            $firstCombat = false;
                            echo json_encode($combat, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }
                    echo ']';
                }
                echo '}';
            }
            echo '}';
            return [];
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
            unset($content);
            if ($mode === LOCK_EX && ($document['state']['archiveDetached'] ?? false) !== true) {
                $this->detachLegacyArchive($id, $document, $handle);
            }
            return $callback($document, $handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function directory(): string
    {
        $configured = getenv('WAAR_BAGAAR_RUN_DIRECTORY');
        $directory = $this->directory !== '' ? $this->directory
            : ($configured !== false && $configured !== '' ? $configured : sys_get_temp_dir().DIRECTORY_SEPARATOR.'waar-bagaar-runs');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire Bagaar inaccessible.');
        }
        return $directory;
    }

    private function write($handle, array $document): void
    {
        $staged = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($staged === false) {
            throw new \RuntimeException('Impossible de préparer la simulation Bagaar.');
        }
        try {
            $this->writeObject($staged, $document, false);
            rewind($staged);
            rewind($handle);
            if (!ftruncate($handle, 0) || stream_copy_to_stream($staged, $handle) === false || !fflush($handle)) {
                throw new \RuntimeException('Impossible d’enregistrer la simulation Bagaar.');
            }
        } finally {
            fclose($staged);
        }
    }

    private function writeObject($handle, array $values, bool $state): void
    {
        $this->writeBytes($handle, '{');
        $first = true;
        foreach ($values as $key => $value) {
            if (!$first) {
                $this->writeBytes($handle, ',');
            }
            $first = false;
            $this->writeBytes($handle, json_encode((string)$key, JSON_THROW_ON_ERROR).':');
            if (!$state && $key === 'state' && is_array($value)) {
                $this->writeObject($handle, $value, true);
            } elseif ($state && in_array($key, ['frames', 'events'], true) && is_array($value)) {
                $this->writeList($handle, $value, $key === 'frames' ? 16 : 256);
            } else {
                $this->writeBytes($handle, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        $this->writeBytes($handle, '}');
    }

    private function writeList($handle, array $items, int $chunkSize): void
    {
        if (!array_is_list($items)) {
            throw new \LogicException('Liste de trames ou d’événements invalide.');
        }
        $this->writeBytes($handle, '[');
        for ($offset = 0, $count = count($items); $offset < $count; $offset += $chunkSize) {
            if ($offset > 0) {
                $this->writeBytes($handle, ',');
            }
            $chunk = json_encode(array_slice($items, $offset, $chunkSize),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->writeBytes($handle, substr($chunk, 1, -1));
        }
        $this->writeBytes($handle, ']');
    }

    private function writeBytes($handle, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Impossible d’enregistrer la simulation Bagaar.');
            }
        }
    }

    private function archivePath(string $id): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$id.'.combats';
    }

    private function tracePath(string $id, string $kind): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$id.'.'.$kind;
    }

    private function outputJsonl(string $path, int $count): void
    {
        echo '[';
        if ($count > 0) {
            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Archive de trace introuvable.');
            }
            try {
                for ($i = 0; $i < $count; $i++) {
                    $line = fgets($stream);
                    if ($line === false) {
                        throw new \RuntimeException('Archive de trace incomplète.');
                    }
                    if ($i > 0) {
                        echo ',';
                    }
                    echo rtrim($line, "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }
        echo ']';
    }

    private function appendDetachedAndWrite(string $id, $stateHandle, array $document, array $batches): void
    {
        $opened = [];
        try {
            foreach ($batches as $kind => $items) {
                if ($items === []) {
                    continue;
                }
                $path = $kind === 'combats' ? $this->archivePath($id) : $this->tracePath($id, $kind);
                $stream = @fopen($path, 'c+b');
                if ($stream === false || fseek($stream, 0, SEEK_END) !== 0) {
                    throw new \RuntimeException('Archive de simulation inaccessible.');
                }
                $opened[] = [$stream, ftell($stream)];
                foreach ($items as $item) {
                    $this->writeArchiveLine($stream, $item);
                }
                fflush($stream);
            }
            $this->write($stateHandle, $document);
        } catch (\Throwable $error) {
            foreach ($opened as [$stream, $offset]) {
                ftruncate($stream, $offset);
            }
            throw $error;
        } finally {
            foreach ($opened as [$stream]) {
                fclose($stream);
            }
        }
    }

    private function detachLegacyArchive(string $id, array &$document, $handle): void
    {
        $path = $this->archivePath($id);
        $temporary = $path.'.migrate-'.bin2hex(random_bytes(4));
        $archive = fopen($temporary, 'xb');
        if ($archive === false) {
            throw new \RuntimeException('Impossible de créer l’archive de combats.');
        }
        try {
            foreach ($document['state']['combats'] as $combat) {
                $this->writeArchiveLine($archive, $combat);
            }
            fflush($archive);
        } finally {
            fclose($archive);
        }
        if (is_file($path)) {
            unlink($path);
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Migration de l’archive de combats impossible.');
        }
        $document['state']['combatCount'] = count($document['state']['combats']);
        $document['state']['archiveDetached'] = true;
        $document['state']['combats'] = [];
        $this->write($handle, $document);
    }

    private function appendAndWrite(string $id, $handle, array $document, array $batch): void
    {
        if ($batch === []) {
            $this->write($handle, $document);
            return;
        }
        $archive = fopen($this->archivePath($id), 'c+b');
        if ($archive === false) {
            throw new \RuntimeException('Archive de combats inaccessible.');
        }
        if (fseek($archive, 0, SEEK_END) !== 0) {
            fclose($archive);
            throw new \RuntimeException('Archive de combats inaccessible.');
        }
        $offset = ftell($archive);
        try {
            foreach ($batch as $combat) {
                $this->writeArchiveLine($archive, $combat);
            }
            fflush($archive);
            $this->write($handle, $document);
        } catch (\Throwable $error) {
            ftruncate($archive, $offset);
            throw $error;
        } finally {
            fclose($archive);
        }
    }

    private function writeArchiveLine($handle, array $combat): void
    {
        $line = json_encode($combat, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Écriture de l’archive de combats impossible.');
            }
            $offset += $written;
        }
    }
}
