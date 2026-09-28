<?php

require dirname(__DIR__).'/autoload.php';

use Waar\MicroCombat\Bagaar\RunStore;

$id = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    fwrite(STDERR, "Usage: php -d memory_limit=512M bin/migrate-bagaar-trace.php RUN_ID\n");
    exit(2);
}
$result = (new RunStore())->migrateTrace($id);
printf("run=%s frames=%d events=%d\n", $id, $result['frames'], $result['events']);
