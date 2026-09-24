<?php

namespace App\Infrastructure\Combat;

final class RustCombatResolver
{
    private ?\FFI $ffi = null;
    public function __construct(private readonly string $projectDir, private readonly ?string $libraryPath = null)
    {
    }
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function resolveRequest(array $request): array
    {
        return $this->call('resolve_combat_json', $request);
    }
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function resolveBatch(array $request): array
    {
        return $this->call('resolve_combat_batch_json', $request);
    }
    public function resolvedLibraryPath(): string
    {
        $configured = $this->libraryPath ?? ($_SERVER['WAAR_COHORT_DLL'] ?? $_ENV['WAAR_COHORT_DLL'] ?? null);
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }
        foreach ([$this->projectDir.'/rust/target/release/waar_cohort.dll', $this->projectDir.'/rust/target/debug/waar_cohort.dll', $this->projectDir.'/rust/target/release/libwaar_cohort.so', $this->projectDir.'/rust/target/release/libwaar_cohort.dylib'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return $this->projectDir.'/rust/target/release/waar_cohort.dll';
    }
    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function call(string $function, array $request): array
    {
        $input = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $ffi = $this->ffi();
        $pointer = $ffi->{$function}($input);
        if (null === $pointer) {
            throw new \RuntimeException('The Rust cohort engine returned a null pointer.');
        }
        try {
            $output = \FFI::string($pointer);
        } finally {
            $ffi->free_combat_string($pointer);
        }
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['error'])) {
            throw new \RuntimeException('Rust cohort engine: '.$payload['error']);
        }
        return $payload;
    }
    private function ffi(): \FFI
    {
        if (!extension_loaded('ffi')) {
            throw new \LogicException('PHP FFI is not enabled.');
        }
        if (null === $this->ffi) {
            $path = $this->resolvedLibraryPath();
            if (!is_file($path)) {
                throw new \RuntimeException("Rust combat library not found: {$path}");
            }
            $this->ffi = \FFI::cdef('char* resolve_combat_json(const char* input_json); char* resolve_combat_batch_json(const char* input_json); void free_combat_string(char* ptr);', $path);
        }
        return $this->ffi;
    }
}
