<?php

namespace Waar\MicroCombat\Bagaar;

/** Runs isolated account policies in one restricted Lua process for a step request. */
final class LuaPolicy implements PlayerPolicy
{
    private const MAX_SOURCE_BYTES = 16_384;
    private const MAX_REPLY_BYTES = 65_536;
    private const MAX_ACTIVE_ACCOUNTS = 64;
    private $process;
    private array $pipes = [];
    private array $memory = [];
    private array $intentions = [];
    private bool $multi;

    /** @param string|array<string,string> $source */
    public function __construct(string|array $source)
    {
        $this->multi = is_array($source);
        $sources = is_string($source) ? ['single' => $source] : $source;
        if ((!$this->multi && $sources === []) || count($sources) > self::MAX_ACTIVE_ACCOUNTS) {
            throw new \InvalidArgumentException('Nombre de scripts Lua invalide.');
        }
        foreach ($sources as $id => $script) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)
                || !is_string($script) || $script === '' || strlen($script) > self::MAX_SOURCE_BYTES
                || preg_match('//u', $script) !== 1) {
                throw new \InvalidArgumentException('Script Lua vide, trop long ou mal encodé.');
            }
        }
        $worker = dirname(__DIR__, 2).'/bin/bagaar-lua-worker.lua';
        $command = ['/usr/bin/prlimit', '--as=134217728', $this->multi ? '--cpu=12' : '--cpu=3',
            '--', '/usr/bin/lua5.4', $worker];
        $this->process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes, null, []);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Interpréteur Lua indisponible.');
        }
        try {
            $response = $this->exchange($this->multi ? ['sources' => $sources] : ['source' => $source]);
            $intentions = $this->multi ? ($response['intentions'] ?? null) : ['single' => $response];
            if (!is_array($intentions) || count($intentions) !== count($sources)
                || array_diff_key($intentions, $sources) !== []) {
                throw new \InvalidArgumentException('Intentions initiales Lua invalides.');
            }
            foreach ($intentions as $id => $intention) {
                if (!is_array($intention)) {
                    throw new \InvalidArgumentException('Intention initiale Lua invalide.');
                }
                $this->validateIntention($intention);
                $this->intentions[$id] = ['goal' => $intention['goal'], 'method' => $intention['method']];
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function next(array $observation): ?array
    {
        return $this->action('next', $observation);
    }

    public function afterCombat(array $observation): ?array
    {
        return $this->action('after_combat', $observation);
    }

    public function accountIds(): ?array
    {
        return $this->multi ? array_keys($this->intentions) : null;
    }

    public function register(string $id, string $source): array
    {
        if (!$this->multi || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)
            || isset($this->intentions[$id]) || count($this->intentions) >= self::MAX_ACTIVE_ACCOUNTS
            || $source === '' || strlen($source) > self::MAX_SOURCE_BYTES || preg_match('//u', $source) !== 1) {
            throw new \InvalidArgumentException('Nouveau compte Lua invalide.');
        }
        $reply = $this->exchange(['method' => 'register', 'accountId' => $id, 'source' => $source]);
        $this->validateIntention($reply);
        return $this->intentions[$id] = ['goal' => $reply['goal'], 'method' => $reply['method']];
    }

    public function unregister(string $id): void
    {
        if (!$this->multi || !isset($this->intentions[$id])) {
            return;
        }
        $this->exchange(['method' => 'unregister', 'accountId' => $id]);
        unset($this->intentions[$id], $this->memory[$id]);
    }

    public function intention(string $id = ''): array
    {
        return $this->intentions[$this->key($id)] ?? throw new \InvalidArgumentException('Compte Lua inconnu.');
    }

    public function state(string $id = ''): array
    {
        $key = $this->key($id);
        return ['memory' => $this->memory[$key] ?? [], ...$this->intention($id)];
    }

    private function action(string $method, array $observation): ?array
    {
        $id = $observation['self']['id'] ?? '';
        $key = $this->key($id);
        $intention = $this->intention($id);
        $reply = $this->exchange(['method' => $method, 'observation' => $observation,
            'goal' => $observation['self']['luaGoal'] ?? $intention['goal'],
            'methodText' => $observation['self']['luaMethod'] ?? $intention['method'],
            ...($this->multi ? ['accountId' => $id] : [])]);
        $action = $reply['action'] ?? null;
        if ($action !== null && (!is_array($action) || array_is_list($action) || !is_string($action['type'] ?? null))) {
            throw new \RuntimeException('Le script Lua doit renvoyer une action ou nil.');
        }
        $memory = $reply['memory'] ?? null;
        if (!is_array($memory) || strlen(json_encode($memory, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Mémoire Lua invalide ou supérieure à 4 Kio.');
        }
        $this->validateIntention($reply);
        $this->memory[$key] = $memory;
        $this->intentions[$key] = ['goal' => $reply['goal'], 'method' => $reply['method']];
        return $action;
    }

    private function key(string $id): string
    {
        if ($this->multi && !array_key_exists($id, $this->intentions)) {
            throw new \InvalidArgumentException('Compte Lua inconnu.');
        }
        return $this->multi ? $id : 'single';
    }

    private function validateIntention(array $reply): void
    {
        foreach (['goal' => 120, 'method' => 240] as $key => $limit) {
            $value = $reply[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > $limit || preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Objectif ou moyen Lua invalide.');
            }
        }
    }

    private function exchange(array $request): array
    {
        $line = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $written = 0;
        while ($written < strlen($line)) {
            $part = @fwrite($this->pipes[0], substr($line, $written));
            if ($part === false || $part === 0) {
                throw new \RuntimeException('Le processus Lua ne répond plus.');
            }
            $written += $part;
        }
        fflush($this->pipes[0]);
        $ready = [$this->pipes[1]];
        $write = $except = [];
        if (stream_select($ready, $write, $except, 1) !== 1) {
            throw new \RuntimeException('Script Lua trop lent.');
        }
        $line = fgets($this->pipes[1], self::MAX_REPLY_BYTES);
        if ($line === false || !str_ends_with($line, "\n")) {
            throw new \RuntimeException('Réponse Lua absente ou trop grande.');
        }
        $reply = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($reply) || ($reply['ok'] ?? false) !== true) {
            throw new \InvalidArgumentException('Script Lua : '.substr((string)($reply['error'] ?? 'erreur inconnue'), 0, 240));
        }
        return $reply;
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
