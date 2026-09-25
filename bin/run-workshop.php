<?php

$host = $argv[1] ?? '127.0.0.1:8080';
if (!preg_match('/\A127\.0\.0\.1:(?:[1-9][0-9]{0,4})\z/', $host)) {
    fwrite(STDERR, "Usage: php bin/run-workshop.php [127.0.0.1:port]\n");
    exit(1);
}
$root = dirname(__DIR__);
fwrite(STDOUT, "Soufflerie Waar : http://$host\nCtrl+C pour arrêter.\n");
passthru(escapeshellarg(PHP_BINARY).' -S '.escapeshellarg($host).' -t '.escapeshellarg($root.'/public/workshop').' '.escapeshellarg($root.'/bin/workshop-router.php'), $code);
exit($code);
