<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/bin',
        __DIR__.'/engines/waar-cohort/src',
        __DIR__.'/engines/waar-cohort/bin',
        __DIR__.'/profiles',
        __DIR__.'/ops/demo',
    ])
    ->append([__DIR__.'/autoload.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'whitespace_after_comma_in_array' => ['ensure_single_space' => true],
    ])
    ->setFinder($finder);
