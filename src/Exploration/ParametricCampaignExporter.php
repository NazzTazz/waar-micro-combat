<?php

declare(strict_types=1);

namespace Waar\MicroCombat\Exploration;

final class ParametricCampaignExporter
{
    /** @return array{experiments:int,directions:int,samples:int,path:string} */
    public static function export(string$output): array
    {
        $manifest = self::read($output.'/manifest.json');
        $experiments = [];
        foreach (glob($output.'/experiments/*.json') ?: [] as $file) {
            $e = self::read($file);
            $experiments[$e['id']] = $e;
        }
        $aggregate = [];
        $ranges = [];
        foreach (glob($output.'/lots/*.json') ?: [] as $file) {
            $lot = self::read($file);
            $eid = $lot['experimentId'];
            $range = $lot['startIteration'].'+'. $lot['iterations'];
            if (isset($ranges[$eid][$range])) {
                throw new \RuntimeException("Lot dupliqué : {$eid}/{$range}");
            }
            $ranges[$eid][$range] = true;
            foreach ($lot['response']['scenarios'] as $scenario) {
                $key = $eid.'|'.$scenario['id'];
                $result = $scenario['result'];
                if (!isset($aggregate[$key])) {
                    $aggregate[$key] = ['experimentId' => $eid, 'direction' => $scenario['id'], 'samples' => 0, 'attackerWins' => 0, 'defenderWins' => 0, 'draws' => 0, 'roundSum' => 0, 'attackerRawDeathsByType' => [0, 0, 0, 0], 'defenderRawDeathsByType' => [0, 0, 0, 0], 'attackerRawWoundedByType' => [0, 0, 0, 0], 'defenderRawWoundedByType' => [0, 0, 0, 0], 'attackerProjectedByType' => array_fill(0, 4, [0, 0, 0, 0]), 'defenderProjectedByType' => array_fill(0, 4, [0, 0, 0, 0])];
                }
                $a = &$aggregate[$key];
                foreach (['samples', 'attackerWins', 'defenderWins', 'draws', 'roundSum'] as $f) {
                    $a[$f] += $result[$f];
                }
                foreach (['attackerRawDeathsByType', 'defenderRawDeathsByType', 'attackerRawWoundedByType', 'defenderRawWoundedByType'] as $f) {
                    foreach (self::TYPES() as $i => $t) {
                        $a[$f][$i] += $result[$f][$i];
                    }
                }
                foreach (['attackerProjectedByType', 'defenderProjectedByType'] as $f) {
                    foreach (self::TYPES() as $i => $t) {
                        foreach ([0, 1, 2, 3] as $j) {
                            $a[$f][$i][$j] += $result[$f][$i][$j];
                        }
                    }
                }
                unset($a);
            }
        }
        ksort($aggregate);
        $rows = [];
        foreach ($aggregate as $a) {
            $e = $experiments[$a['experimentId']] ?? throw new \RuntimeException('Configuration effective absente.');
            [$attacker,$defender] = explode('-', $a['direction']);
            $row = ['experiment_id' => $e['id'], 'scenario_id' => $e['scenarioId'], 'composition_id' => $e['compositionId'], 'axes_json' => ParametricCampaign::canonicalJson($e['axes']), 'attacker' => $attacker, 'defender' => $defender, 'samples' => $a['samples'], 'attacker_wins' => $a['attackerWins'], 'draws' => $a['draws'], 'attacker_losses' => $a['defenderWins'], 'mean_rounds' => $a['samples'] ? $a['roundSum'] / $a['samples'] : null];
            foreach (['A', 'B'] as $camp) {
                $row['budget_'.$camp] = $e['armies'][$camp]['actualBudget'];
                $row['target_budget_'.$camp] = $e['armies'][$camp]['targetBudget'];
                $row['remainder_'.$camp] = $e['armies'][$camp]['remainder'];
                foreach (self::TYPES() as $i => $type) {
                    $row[$camp.'_initial_'.$type] = $e['armies'][$camp]['units'][$type];
                }
            }
            $costs = [];
            foreach ($e['profile']['units'] as $type => $unit) {
                $costs[$type] = $unit['cost'];
            }
            foreach (['attacker' => $attacker, 'defender' => $defender] as $side => $camp) {
                $lost = 0;
                foreach (self::TYPES() as $i => $type) {
                    $prefix = $side;
                    $row[$camp.'_raw_dead_'.$type] = $a[$prefix.'RawDeathsByType'][$i] / $a['samples'];
                    $row[$camp.'_raw_wounded_'.$type] = $a[$prefix.'RawWoundedByType'][$i] / $a['samples'];
                    $projected = $a[$prefix.'ProjectedByType'][$i];
                    $row[$camp.'_projected_dead_'.$type] = $projected[2] / $a['samples'];
                    $row[$camp.'_projected_wounded_'.$type] = $projected[1] / $a['samples'];
                    $row[$camp.'_projected_prisoners_'.$type] = $projected[3] / $a['samples'];
                    $lost += ($projected[1] + $projected[2]) * $costs[$type];
                }
                $row[$camp.'_bench_economic_loss_rate'] = $e['armies'][$camp]['actualBudget'] ? ($lost / $a['samples'] / $e['armies'][$camp]['actualBudget']) : null;
            }
            $rows[] = $row;
        }
        $path = $output.'/exports/results.csv';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Création export impossible.');
        }
        $tmp = tempnam(dirname($path), '.tmp-');
        $h = fopen($tmp, 'wb');
        if ($rows) {
            $header = array_keys($rows[0]);
            fputcsv($h, $header);
            foreach ($rows as $row) {
                fputcsv($h, array_map(static fn (string $key) => $row[$key], $header));
            }
        }
        fclose($h);
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('Publication export impossible.');
        }
        $sim = (float)($manifest['simulationSeconds'] ?? 0);
        ParametricCampaign::atomicJson($output.'/exports/summary.json', ['manifestStatus' => $manifest['status'], 'experiments' => count($experiments), 'directions' => count($rows), 'samples' => array_sum(array_column($rows, 'samples')), 'simulationSeconds' => $sim, 'measuredCombatsPerSecond' => $sim > 0 ? $manifest['completedCombats'] / $sim : null, 'metricDefinitions' => ['mean_rounds' => 'Somme entière des rounds divisée par les échantillons.', 'bench_economic_loss_rate' => 'Valeur au coût du profil des morts + blessés projetés, divisée par la valeur initiale du camp; prisonniers exclus; ce n’est pas une facture de soins.'], 'limitations' => ['Les sorties batch sont des agrégats. Aucune distribution, quantile ou différence appariée par répétition ne peut être reconstruite.']]);
        return['experiments' => count($experiments), 'directions' => count($rows), 'samples' => array_sum(array_column($rows, 'samples')), 'path' => $path];
    }
    private static function TYPES(): array
    {
        return ParametricCampaign::TYPES;
    }
    private static function read(string$path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Fichier absent : {$path}");
        }
        $v = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($v)) {
            throw new \RuntimeException("JSON invalide : {$path}");
        }
        return$v;
    }
}
