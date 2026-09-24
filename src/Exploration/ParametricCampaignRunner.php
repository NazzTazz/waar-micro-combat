<?php

declare(strict_types=1);

namespace Waar\MicroCombat\Exploration;

use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

final class ParametricCampaignRunner
{
    public function __construct(private readonly CohortRuntime $runtime = new ProcessCohortRuntime(null, null, true), private readonly ?string $binaryPath = null)
    {
    }

    /** @return array<string,mixed> */
    public function run(array $loaded, ?int $stopAfterLots = null): array
    {
        $plan = $loaded['plan'];
        $profile = $loaded['profile'];
        $output = $loaded['outputPath'];
        $preview = ParametricCampaign::preview($plan, $profile);
        $provenance = $this->provenance();
        $identity = ['schemaVersion' => 'waar-parametric-run/1', 'planSha256' => hash_file('sha256', $loaded['planPath']), 'profileSha256' => hash_file('sha256', $loaded['profilePath']), 'effectivePlanSha256' => hash('sha256', ParametricCampaign::canonicalJson($plan)), 'engine' => $provenance, 'sampling' => $plan['sampling']];
        $manifestPath = $output.'/manifest.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
            if (($manifest['identity'] ?? null) !== $identity) {
                throw new \RuntimeException('Reprise refusée : moteur, profil, plan ou sampling incompatible.');
            }
        } else {
            if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
                throw new \RuntimeException("Création impossible : {$output}");
            }
            copy($loaded['planPath'], $output.'/plan.json');
            copy($loaded['profilePath'], $output.'/profile.json');
            $manifest = ['identity' => $identity, 'status' => 'running', 'preview' => $preview, 'completedLots' => 0, 'completedCombats' => 0, 'errors' => [], 'startedAt' => gmdate('c'), 'updatedAt' => gmdate('c')];
            ParametricCampaign::atomicJson($manifestPath, $manifest);
        }
        $completed = $this->completedLots($output);
        $manifest['completedLots'] = count($completed['keys']);
        $manifest['completedCombats'] = $completed['combats'];
        $manifest['simulationSeconds'] = $completed['seconds'];
        ParametricCampaign::atomicJson($manifestPath, $manifest);
        $lotsDone = 0;
        $started = hrtime(true);
        $newCombats = 0;
        foreach (ParametricCampaign::experiments($plan, $profile) as $experiment) {
            ParametricCampaign::atomicJson($output.'/experiments/'.$experiment['id'].'.json', $experiment);
            for ($start = 0;$start < $plan['sampling']['repetitions'];$start += $plan['sampling']['batchSize']) {
                $count = min($plan['sampling']['batchSize'], $plan['sampling']['repetitions'] - $start);
                $key = $experiment['id'].'-'.$start.'-'.$count;
                $path = $output.'/lots/'.$key.'.json';
                if (isset($completed['keys'][$key])) {
                    continue;
                }
                $request = ParametricCampaign::batchRequest($experiment, $plan['sampling']['baseSeed'], $start, $count, $plan['sampling']['repetitions']);
                $t0 = hrtime(true);
                try {
                    $response = $this->runtime->batch($request);
                    ParametricCampaign::assertResponse($response, $request);
                    $elapsed = (hrtime(true) - $t0) / 1e9;
                    ParametricCampaign::atomicJson($path, ['schemaVersion' => 'waar-parametric-lot/1', 'key' => $key, 'experimentId' => $experiment['id'], 'startIteration' => $start, 'iterations' => $count, 'totalIterations' => $plan['sampling']['repetitions'], 'request' => $request, 'response' => $response, 'elapsedSeconds' => $elapsed, 'completedAt' => gmdate('c')]);
                    $lotsDone++;
                    $newCombats += $response['totalCombats'];
                    $manifest['completedLots']++;
                    $manifest['completedCombats'] += $response['totalCombats'];
                    $manifest['simulationSeconds'] += $elapsed;
                    $manifest['updatedAt'] = gmdate('c');
                    ParametricCampaign::atomicJson($manifestPath, $manifest);
                    $wall = (hrtime(true) - $started) / 1e9;
                    $rate = $wall > 0 ? $newCombats / $wall : 0;
                    fwrite(STDERR, sprintf("\rLots %d, combats %d/%d, %.1f combats/s", $manifest['completedLots'], $manifest['completedCombats'], $preview['combats'], $rate));
                    if ($stopAfterLots !== null && $lotsDone >= $stopAfterLots) {
                        fwrite(STDERR, "\nInterruption de validation demandée.\n");
                        return$manifest;
                    }
                } catch (\Throwable$e) {
                    $error = ['experimentId' => $experiment['id'], 'startIteration' => $start, 'iterations' => $count, 'message' => $e->getMessage(), 'at' => gmdate('c')];
                    $manifest['errors'][] = $error;
                    $manifest['status'] = 'partial';
                    $manifest['updatedAt'] = gmdate('c');
                    ParametricCampaign::atomicJson($output.'/errors/'.hash('sha256', $key.$error['at']).'.json', $error);
                    ParametricCampaign::atomicJson($manifestPath, $manifest);
                    throw$e;
                }
            }
        }
        $manifest['status'] = $manifest['completedCombats'] === $preview['combats'] ? 'complete' : 'partial';
        $manifest['finishedAt'] = gmdate('c');
        $manifest['updatedAt'] = gmdate('c');
        ParametricCampaign::atomicJson($manifestPath, $manifest);
        fwrite(STDERR, "\n");
        return$manifest;
    }

    /** @return array<string,mixed> */
    public function provenance(): array
    {
        $root = dirname(__DIR__, 2);
        $p = $this->runtime->provenance();
        $binary = $this->binaryPath ?? self::defaultBinary();
        $p['binaryPath'] = $binary;
        $p['binarySha256'] = is_file($binary) ? hash_file('sha256', $binary) : null;
        $p['codeHead'] = self::command(['git', 'rev-parse', 'HEAD']);
        $p['trackedDiffSha256'] = hash('sha256', self::command(['git', 'diff', '--binary', 'HEAD', '--', 'src/Workshop', 'src/Exploration', 'engines/waar-cohort/rust/src']));
        $p['sourceSha256'] = [];
        foreach (['src/Workshop/CohortRequestFactory.php', 'src/Workshop/EngineProfile.php', 'src/Workshop/ProcessCohortRuntime.php', 'src/Exploration/ParametricCampaign.php', 'src/Exploration/ParametricCampaignRunner.php', 'engines/waar-cohort/rust/src/v2.rs'] as $file) {
            $p['sourceSha256'][$file] = hash_file('sha256', $root.'/'.$file);
        }
        $p['stochasticEngineVersion'] = CohortRequestFactory::STOCHASTIC_VERSION;
        $p['samplingProtocol'] = CohortRequestFactory::SAMPLING_PROTOCOL;
        $p['consequencePolicyVersion'] = CohortRequestFactory::POLICY_VERSION;
        return$p;
    }

    /** @return array{keys:array<string,bool>,combats:int,seconds:float} */
    private function completedLots(string$output): array
    {
        $done = [];
        $combats = 0;
        $seconds = 0.0;
        foreach (glob($output.'/lots/*.json') ?: [] as $file) {
            $lot = json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
            $key = $lot['key'] ?? '';
            if ($key === '' || isset($done[$key])) {
                throw new \RuntimeException('Lot enregistré invalide ou dupliqué.');
            }
            if (($lot['response']['iterations'] ?? null) !== ($lot['iterations'] ?? null)) {
                throw new \RuntimeException("Lot incomplet : {$file}");
            }
            $done[$key] = true;
            $combats += (int)$lot['response']['totalCombats'];
            $seconds += (float)$lot['elapsedSeconds'];
        }
        return['keys' => $done, 'combats' => $combats, 'seconds' => $seconds];
    }
    private static function defaultBinary(): string
    {
        return dirname(__DIR__, 2).'/engines/waar-cohort/rust/target/release/waar-cohort-cli'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }
    private static function command(array$command): string
    {
        $p = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($p)) {
            return'unavailable';
        }
        $out = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($p) === 0 ? $out : 'unavailable';
    }
}
