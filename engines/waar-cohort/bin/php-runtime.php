<?php

use App\Game\Combat\CombatEngine;

require dirname(__DIR__).'/autoload.php';

/** @param array<string,mixed> $batch @return array<string,mixed> */
function resolvePhpBatch(array $batch): array
{
    $allowed = ['schemaVersion', 'ruleset', 'baseSeed', 'iterations', 'startIteration', 'totalIterations', 'consequences', 'scenarios', 'stochasticEngineVersion'];
    if (array_diff(array_keys($batch), $allowed)) {
        throw new InvalidArgumentException('Unknown combat batch field.');
    }
    if (($batch['schemaVersion'] ?? null) !== 'waar-combat-batch-request/2') {
        throw new InvalidArgumentException('Unsupported combat batch schema.');
    }
    $iterations = $batch['iterations'] ?? null;
    $start = $batch['startIteration'] ?? 0;
    $total = $batch['totalIterations'] ?? ($start + (is_int($iterations) ? $iterations : 0));
    $baseSeed = $batch['baseSeed'] ?? null;
    if (!is_int($iterations) || $iterations < 1 || $iterations > 100 || !is_int($start) || $start < 0 || !is_int($total) || $total < 1 || $total > 100 || $start + $iterations > $total || !is_int($baseSeed) || $baseSeed < 0 || $baseSeed > 2147483647) {
        throw new InvalidArgumentException('Invalid combat batch request.');
    }
    $scenarios = $batch['scenarios'] ?? null;
    if (!is_array($scenarios) || !array_is_list($scenarios) || $scenarios === []) {
        throw new InvalidArgumentException('Batch scenarios are required.');
    }
    $types = ['soldier', 'spearman', 'archer', 'knight'];
    $categories = ['healthy', 'wounded', 'dead', 'prisoners'];
    $engine = new CombatEngine();
    $results = [];
    $seen = [];
    foreach ($scenarios as $scenario) {
        if (!is_array($scenario) || !is_string($scenario['id'] ?? null) || trim($scenario['id']) === '' || isset($seen[$scenario['id']])) {
            throw new InvalidArgumentException('Scenario id missing or duplicated.');
        }
        if (array_diff(array_keys($scenario), ['id', 'seedKey', 'attacker', 'defender', 'armyIdentities'])) {
            throw new InvalidArgumentException('Unknown scenario field.');
        }
        $seen[$scenario['id']] = true;
        $wins = ['attacker' => 0, 'defender' => 0, 'draw' => 0];
        $roundSum = 0;
        $wounded = ['attacker' => array_fill(0, 4, 0), 'defender' => array_fill(0, 4, 0)];
        $deaths = ['attacker' => array_fill(0, 4, 0), 'defender' => array_fill(0, 4, 0)];
        $projected = ['attacker' => array_fill(0, 4, array_fill(0, 4, 0)), 'defender' => array_fill(0, 4, array_fill(0, 4, 0))];
        foreach (range($start, $start + $iterations - 1) as $iteration) {
            $key = $scenario['seedKey'] ?? null;
            if ($key !== null) {
                if (!is_int($key) || $key < 0 || $key > 2147) {
                    throw new InvalidArgumentException('Invalid scenario seedKey.');
                }
                $seed = ($baseSeed + $key * 1000003 + $iteration) % 2147483647;
            } else {
                $bytes = hash('sha256', $baseSeed."\0".$scenario['id']."\0".$iteration, true);
                $parts = unpack('Nseed', substr($bytes, 0, 4));
                $seed = $parts['seed'] & 0x7fffffff;
            }
            $request = ['schemaVersion' => 'waar-combat-request/2', 'ruleset' => $batch['ruleset'], 'attacker' => $scenario['attacker'], 'defender' => $scenario['defender'], 'seed' => $seed, 'traceLevel' => 'none'];
            if (isset($batch['consequences'])) {
                $request['consequences'] = $batch['consequences'];
            }
            if (array_key_exists('stochasticEngineVersion', $batch)) {
                $request['stochasticEngineVersion'] = $batch['stochasticEngineVersion'];
            }
            if (array_key_exists('armyIdentities', $scenario)) {
                $request['armyIdentities'] = $scenario['armyIdentities'];
            }
            $report = $engine->resolveRequest($request);
            $winner = $report['result']['winner'];
            $wins[$winner ?? 'draw']++;
            $roundSum += count($report['result']['rounds']);
            foreach (['attacker', 'defender'] as $side) {
                foreach ($types as $typeIndex => $type) {
                    $wounded[$side][$typeIndex] += $report['result'][$side]['wounded'][$type];
                    $deaths[$side][$typeIndex] += $report['result'][$side]['dead'][$type];
                    if (isset($report['consequences'])) {
                        foreach ($categories as $categoryIndex => $category) {
                            $projected[$side][$typeIndex][$categoryIndex] += $report['consequences'][$side]['types'][$type]['projected'][$category];
                        }
                    }
                }
            }
        }
        $initial = static fn (string $side): array => array_map(static fn (string $type): int => (int)($scenario[$side]['units'][$type] ?? 0), $types);
        $results[] = ['id' => $scenario['id'], ...(isset($scenario['armyIdentities']) ? ['armyIdentities' => $scenario['armyIdentities']] : []), 'result' => ['samples' => $iterations, 'attackerWins' => $wins['attacker'], 'defenderWins' => $wins['defender'], 'draws' => $wins['draw'], 'roundSum' => $roundSum,
            'attackerInitialByType' => $initial('attacker'), 'defenderInitialByType' => $initial('defender'), 'attackerRawDeathsByType' => $deaths['attacker'], 'defenderRawDeathsByType' => $deaths['defender'],
            'attackerRawWoundedByType' => $wounded['attacker'], 'defenderRawWoundedByType' => $wounded['defender'],
            'attackerProjectedByType' => isset($batch['consequences']) ? $projected['attacker'] : null, 'defenderProjectedByType' => isset($batch['consequences']) ? $projected['defender'] : null]];
    }
    $provenance = [];
    if (isset($batch['consequences'])) {
        $settings = $batch['consequences'];
        $version = $settings['policyVersion'] ?? \App\Game\Combat\ConsequencePolicy::VERSION;
        $provenance = ['consequenceProvenance' => ['policyVersion' => $version, 'samplingProtocol' => \App\Game\Combat\ConsequencePolicy::samplingProtocol($version), 'compressionPercent' => $settings['compressionPercent'], 'capturePercent' => $settings['capturePercent']]];
    }
    if (($batch['stochasticEngineVersion'] ?? null) === \App\Game\Random\AddressedRandom::VERSION) {
        $provenance['stochasticEngineVersion'] = \App\Game\Random\AddressedRandom::VERSION;
    }
    if (isset($report['result']['ruleset']['woundDamageThreshold'])) {
        $provenance['classificationProvenance'] = ['woundDamageThreshold' => $report['result']['ruleset']['woundDamageThreshold']];
    }
    return [...$provenance, 'schemaVersion' => 'waar-combat-batch-result/2', 'modelVersion' => 'waar-cohort-v2', 'unitOrder' => $types, 'projectedCategoryOrder' => $categories, 'iterations' => $iterations, 'startIteration' => $start,
        'iterationRange' => ['start' => $start, 'endExclusive' => $start + $iterations, 'total' => $total, 'complete' => $start === 0 && $iterations === $total], 'totalCombats' => $iterations * count($scenarios), 'scenarios' => $results];
}

foreach (file('php://stdin', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    try {
        $document = json_decode(ltrim($line, "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
        $request = $document['request'] ?? null;
        if (!is_array($request)) {
            throw new InvalidArgumentException('missing request');
        }
        $result = match($document['operation'] ?? null) {
            'resolve' => (new CombatEngine())->resolveRequest($request),'batch' => resolvePhpBatch($request),default => throw new InvalidArgumentException('operation must be resolve or batch')
        };
    } catch (Throwable $error) {
        $result = ['error' => $error->getMessage()];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
}
