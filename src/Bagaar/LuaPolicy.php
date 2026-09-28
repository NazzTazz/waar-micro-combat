<?php

namespace Waar\MicroCombat\Bagaar;

/** Runs one user policy in a restricted Lua process for the lifetime of a step request. */
final class LuaPolicy implements PlayerPolicy
{
    private const MAX_SOURCE_BYTES = 16_384;
    private const MAX_REPLY_BYTES = 65_536;
    private $process;
    private array $pipes = [];
    private array $memory = [];
    private string $goal;
    private string $method;

    public function __construct(private readonly string $source)
    {
        if ($source === '' || strlen($source) > self::MAX_SOURCE_BYTES || preg_match('//u', $source) !== 1) {
            throw new \InvalidArgumentException('Script Lua vide, trop long ou mal encodé.');
        }
        $worker = dirname(__DIR__, 2).'/bin/bagaar-lua-worker.lua';
        $command = ['/usr/bin/prlimit', '--as=134217728', '--cpu=3', '--', '/usr/bin/lua5.4', $worker];
        $this->process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes, null, []);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Interpréteur Lua indisponible.');
        }
        try {
            $response = $this->exchange(['source' => $source]);
            $this->validateIntention($response);
            $this->goal = $response['goal'];
            $this->method = $response['method'];
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

    public function intention(): array
    {
        return ['goal' => $this->goal, 'method' => $this->method];
    }

    public function state(): array
    {
        return ['memory' => $this->memory, 'goal' => $this->goal, 'method' => $this->method];
    }

    private function action(string $method, array $observation): ?array
    {
        $reply = $this->exchange(['method' => $method, 'observation' => $observation,
            'goal' => $observation['self']['luaGoal'] ?? $this->goal,
            'methodText' => $observation['self']['luaMethod'] ?? $this->method]);
        $action = $reply['action'] ?? null;
        if ($action !== null && (!is_array($action) || array_is_list($action) || !is_string($action['type'] ?? null))) {
            throw new \RuntimeException('Le script Lua doit renvoyer une action ou nil.');
        }
        $memory = $reply['memory'] ?? null;
        if (!is_array($memory) || strlen(json_encode($memory, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Mémoire Lua invalide ou supérieure à 4 Kio.');
        }
        $this->validateIntention($reply);
        $this->memory = $memory;
        $this->goal = $reply['goal'];
        $this->method = $reply['method'];
        return $action;
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
