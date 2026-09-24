<?php

declare(strict_types=1);

// PHP CS Fixer's @PSR12 does not split every `}foreach` or `}$next` boundary.
$statementStarts = [
    T_VARIABLE, T_FOREACH, T_IF, T_RETURN, T_TRY, T_FOR, T_THROW,
    T_SWITCH, T_DO, T_ECHO, T_UNSET, T_BREAK, T_CONTINUE,
    T_FUNCTION, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC,
    T_CONST, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE,
    T_STRING, T_MATCH, T_EXIT, T_YIELD, T_NEW,
];

$root = dirname(__DIR__);
$paths = [$root.'/autoload.php'];
foreach (['src', 'tests', 'bin', 'engines/waar-cohort/src', 'engines/waar-cohort/bin', 'profiles', 'ops/demo'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root.'/'.$directory,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
}

$violations = 0;
foreach ($paths as $path) {
    $tokens = token_get_all(file_get_contents($path));
    $line = 1;
    foreach ($tokens as $index => $token) {
        $value = is_array($token) ? $token[1] : $token;
        if ($token === '}') {
            $next = $index + 1;
            $between = '';
            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $between .= $tokens[$next][1];
                ++$next;
            }
            $following = $tokens[$next] ?? null;
            if (!str_contains($between, "\n") && is_array($following) && in_array($following[0], $statementStarts, true)) {
                fwrite(STDERR, substr($path, strlen($root) + 1).':'.$line.": statement after closing brace on the same line\n");
                ++$violations;
            }
        }
        $line += substr_count($value, "\n");
    }
}

if ($violations !== 0) {
    fwrite(STDERR, "$violations PHP block boundary violations.\n");
    exit(1);
}
echo 'PHP block boundaries: clean ('.count($paths)." files).\n";
