<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'Waar\\MicroCombat\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});
