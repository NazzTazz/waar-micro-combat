<?php

namespace Waar\MicroCombat\Bagaar;

/** Immutable shared Lua versions and reusable population snapshots. */
final class SharedBagaarLibrary
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $base = $directory ?? (getenv('WAAR_BAGAAR_RUN_DIRECTORY') ?: dirname(__DIR__, 2).'/reports/bagaar-runs');
        $this->directory = rtrim($base, '/\\').'/library';
    }

    public function listing(): array
    {
        return ['scripts' => $this->listKind('scripts'), 'populations' => $this->listKind('populations')];
    }

    public function checkScript(string $source): array
    {
        if ($source === '' || strlen($source) > 16_384 || preg_match('//u', $source) !== 1) {
            throw new \InvalidArgumentException('Script Lua vide, trop long ou mal encodé.');
        }
        $meta = [];
        foreach (array_slice(preg_split('/\r?\n/', $source), 0, 20) as $line) {
            if (preg_match('/^--\s*@(?<key>profile|author|version)\s+(?<value>.+?)\s*$/u', $line, $match)) {
                $meta[$match['key']] = trim($match['value']);
            }
        }
        foreach (['profile', 'author', 'version'] as $key) {
            if (!isset($meta[$key]) || $meta[$key] === '' || strlen($meta[$key]) > 100
                || preg_match('/[\x00-\x1f]/', $meta[$key])) {
                throw new \InvalidArgumentException('En-tête Lua incomplet : @profile, @author et @version requis.');
            }
        }
        return ['metadata' => $meta, 'parameters' => LuaPolicy::describe($source), 'sha256' => hash('sha256', $source)];
    }

    public function publishScript(string $source): array
    {
        $checked = $this->checkScript($source);
        return $this->save('scripts', ['source' => $source, ...$checked], function (array $rows) use ($checked): void {
            foreach ($rows as $row) {
                if ($row['metadata'] === $checked['metadata']) {
                    throw new \RuntimeException('Cette version de script existe déjà.', 409);
                }
            }
        });
    }

    public function loadScript(string $id): array
    {
        return $this->load('scripts', $id);
    }

    public function savePopulation(string $name, array $accounts, array $spares): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name)
            || count($accounts) < 2 || count($accounts) > 32 || count($spares) > 64
            || !array_is_list($accounts) || !array_is_list($spares)) {
            throw new \InvalidArgumentException('Population invalide.');
        }
        foreach ([$accounts, $spares] as $entries) {
            foreach ($entries as $entry) {
                if (!is_array($entry) || !in_array($entry['policy'] ?? null, [...BuiltinPolicy::NAMES, 'lua'], true)) {
                    throw new \InvalidArgumentException('Modèle de joueur invalide.');
                }
                if ($entry['policy'] === 'lua') {
                    $this->loadScript((string)($entry['scriptKey'] ?? ''));
                }
            }
        }
        return $this->save('populations', ['name' => $name, 'accounts' => $accounts, 'spares' => $spares],
            static function (array $rows) use ($name): void {
                foreach ($rows as $row) {
                    if (strcasecmp($row['name'], $name) === 0) {
                        throw new \RuntimeException('Ce nom de population existe déjà. Enregistrez une nouvelle révision.', 409);
                    }
                }
            });
    }

    public function loadPopulation(string $id): array
    {
        return $this->load('populations', $id);
    }

    private function listKind(string $kind): array
    {
        $rows = [];
        foreach (glob($this->directory.'/'.$kind.'/*.json') ?: [] as $path) {
            $row = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            unset($row['source'], $row['accounts'], $row['spares']);
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        return $rows;
    }

    private function load(string $kind, string $id): array
    {
        if (!preg_match('/^[sp][a-f0-9]{31}$/', $id)) throw new \InvalidArgumentException('Identifiant de bibliothèque invalide.');
        $path = $this->directory.'/'.$kind.'/'.$id.'.json';
        if (!is_file($path)) throw new \RuntimeException('Élément de bibliothèque introuvable.', 404);
        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    private function save(string $kind, array $row, callable $check): array
    {
        $directory = $this->directory.'/'.$kind;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Bibliothèque Bagaar indisponible.', 503);
        }
        $lock = fopen($this->directory.'/library.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) fclose($lock);
            throw new \RuntimeException('Bibliothèque occupée. Réessayez.', 503);
        }
        try {
            $existing = [];
            foreach (glob($directory.'/*.json') ?: [] as $path) {
                $existing[] = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            }
            $check($existing);
            $row = ['id' => ($kind === 'scripts' ? 's' : 'p').substr(bin2hex(random_bytes(16)), 0, 31),
                'createdAt' => gmdate('c'), ...$row];
            $path = $directory.'/'.$row['id'].'.json';
            $handle = fopen($path, 'x');
            if ($handle === false) throw new \RuntimeException('Écriture impossible.', 503);
            try {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            } finally { fclose($handle); }
            return $row;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
