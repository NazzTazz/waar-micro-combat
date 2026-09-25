<?php

require dirname(__DIR__).'/autoload.php';

use App\Game\Combat\CombatEngine;
use App\Game\Combat\CanonicalJson;
use App\Game\Combat\DemoRequestFactory;
use App\Infrastructure\Combat\RustCombatResolver;

$php = new CombatEngine();
$rust = new RustCombatResolver(dirname(__DIR__));
$request = DemoRequestFactory::combat();
$started = hrtime(true);
$phpResult = $php->resolveRequest($request);
$phpMs = (hrtime(true) - $started) / 1e6;
$started = hrtime(true);
$rustResult = $rust->resolveRequest($request);
$rustMs = (hrtime(true) - $started) / 1e6;
$started = hrtime(true);
$batch = $rust->resolveBatch(DemoRequestFactory::batch());
$batchMs = (hrtime(true) - $started) / 1e6;
function firstDifference(mixed $left, mixed $right, string $path = '$'): ?array
{
    if (gettype($left) !== gettype($right) || (!is_array($left) && $left !== $right)) {
        return ['path' => $path, 'php' => $left, 'rust' => $right];
    }
    if (is_array($left)) {
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) {
                return ['path' => $path.'.'.$key, 'php' => $left[$key] ?? null, 'rust' => $right[$key] ?? null];
            }
            $difference = firstDifference($left[$key], $right[$key], $path.'.'.$key);
            if (null !== $difference) {
                return$difference;
            }
        }
    }
    return null;
}
$phpPhysical = ['rounds' => $phpResult['result']['rounds'], 'attacker' => $phpResult['result']['attacker'], 'defender' => $phpResult['result']['defender'], 'snapshot' => $phpResult['result']['snapshot'], 'consequences' => $phpResult['consequences']];
$rustPhysical = ['rounds' => $rustResult['result']['rounds'], 'attacker' => $rustResult['result']['attacker'], 'defender' => $rustResult['result']['defender'], 'snapshot' => $rustResult['result']['snapshot'], 'consequences' => $rustResult['consequences']];
$parity = CanonicalJson::encode($phpResult) === CanonicalJson::encode($rustResult);
echo json_encode(['schemaVersion' => 'waar-cohort-slice-a-demo/1', 'parity' => $parity, 'parityDifference' => firstDifference($phpResult, $rustResult), 'physicalDifference' => firstDifference($phpPhysical, $rustPhysical), 'phpMilliseconds' => $phpMs, 'rustMilliseconds' => $rustMs,
    'batchMilliseconds' => $batchMs, 'combat' => $phpResult, 'batch' => $batch], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR).PHP_EOL;
if (!$parity) {
    exit(1);
}
