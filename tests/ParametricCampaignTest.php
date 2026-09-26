<?php

declare(strict_types=1);

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Exploration\ParametricCampaign;
use Waar\MicroCombat\Exploration\ParametricCampaignExporter;
use Waar\MicroCombat\Exploration\ParametricCampaignRunner;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class ParametricCampaignTest extends TestCase
{
    private array $paths = [];
    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            self::remove($path);
        }
    }

    public function testCartesianWitnessesDeduplicationAndStableIds(): void
    {
        $plan = $this->plan();
        $plan['axes'] = ['a' => ['path' => 'units.soldier.attack', 'values' => ['7', '8']], 'b' => ['path' => 'units.archer.baseAccuracy', 'values' => ['0.15', '0.2']], 'c' => ['path' => 'combat.maxRounds', 'values' => [3, 4]]];
        $plan['crosses'] = ['singles' => true, 'pairs' => [], 'triplets' => [['a', 'b', 'c']]];
        $first = iterator_to_array(ParametricCampaign::experiments($plan, EngineProfile::defaults()));
        $second = iterator_to_array(ParametricCampaign::experiments($plan, EngineProfile::defaults()));
        self::assertCount(8, $first);
        self::assertSame(array_column($first, 'id'), array_column($second, 'id'));
        self::assertContains(['a' => '8', 'b' => '0.2', 'c' => 4], array_column($first, 'axes'));
        $plan['axes']['a']['values'] = ['7', '7'];
        self::assertCount(4, iterator_to_array(ParametricCampaign::experiments($plan, EngineProfile::defaults())));
    }

    public function testRejectsUnknownPathsAndInvalidValues(): void
    {
        $plan = $this->plan();
        $plan['axes'] = ['bad' => ['path' => 'units.soldier.typo', 'values' => ['7']]];
        $files = $this->writePlan($plan);
        $this->expectException(\InvalidArgumentException::class);
        ParametricCampaign::load($files['plan']);
    }

    public function testRejectsInvalidKnownPathValue(): void
    {
        $plan = $this->plan();
        $plan['axes'] = ['bad' => ['path' => 'units.soldier.cost', 'values' => [0]]];
        $files = $this->writePlan($plan);
        $this->expectException(\InvalidArgumentException::class);
        ParametricCampaign::load($files['plan']);
    }

    public function testBudgetArmiesRecalculateAfterPriceChangeAndReplacementLeavesRemainder(): void
    {
        $plan = $this->plan();
        $plan['axes'] = ['price' => ['path' => 'units.archer.cost', 'values' => [200]]];
        $plan['scenarios'][0]['armies']['A'] = ['mode' => 'budgetShares', 'budget' => 1000, 'shares' => ['archer' => 1.0], 'fillRemainderWith' => null];
        $plan['scenarios'][0]['compositionVariants'] = [['id' => 'reference', 'operations' => []], ['id' => 'replace', 'operations' => [['camp' => 'B', 'mode' => 'replaceBudget', 'from' => 'soldier', 'to' => 'archer', 'count' => 3]]]];
        $rows = iterator_to_array(ParametricCampaign::experiments($plan, EngineProfile::defaults()));
        $price = array_values(array_filter($rows, fn ($r) => isset($r['axes']['price']) && $r['compositionId'] === 'reference'))[0];
        self::assertSame(5, $price['armies']['A']['units']['archer']);
        self::assertSame(1000, $price['armies']['A']['actualBudget']);
        $replace = array_values(array_filter($rows, fn ($r) => $r['compositionId'] === 'replace' && $r['axes'] === []))[0];
        self::assertSame(7, $replace['armies']['B']['units']['soldier']);
        self::assertSame(1, $replace['armies']['B']['units']['archer']);
        self::assertSame(110, $replace['armies']['B']['remainder']);
    }

    public function testDirectionsKeepArmyIdentityWeatherAndEffects(): void
    {
        $plan = $this->plan();
        $e = iterator_to_array(ParametricCampaign::experiments($plan, EngineProfile::defaults()))[0];
        $request = ParametricCampaign::batchRequest($e, 42, 2, 2, 4);
        self::assertSame(['attacker' => 'A', 'defender' => 'B'], $request['scenarios'][0]['armyIdentities']);
        self::assertSame(['attacker' => 'B', 'defender' => 'A'], $request['scenarios'][1]['armyIdentities']);
        self::assertSame($e['armies']['A']['units'], $request['scenarios'][0]['attacker']['units']);
        self::assertSame($e['armies']['A']['units'], $request['scenarios'][1]['defender']['units']);
        self::assertSame(2, $request['startIteration']);
        self::assertSame(4, $request['totalIterations']);
        self::assertSame(0, $request['scenarios'][0]['seedKey']);
    }

    public function testResumeSkipsCompleteLotsAndExporterWeightsUnequalLots(): void
    {
        $plan = $this->plan();
        $plan['sampling'] = ['repetitions' => 3, 'baseSeed' => 42, 'batchSize' => 2];
        $files = $this->writePlan($plan);
        $loaded = ParametricCampaign::load($files['plan']);
        $runtime = new FakeCampaignRuntime();
        $runner = new ParametricCampaignRunner($runtime, __FILE__);
        $partial = $runner->run($loaded, 1);
        self::assertSame(4, $partial['completedCombats']);
        self::assertSame(1, $runtime->calls);
        $complete = $runner->run($loaded);
        self::assertSame('complete', $complete['status']);
        self::assertSame(2, $runtime->calls);
        $runner->run($loaded);
        self::assertSame(2, $runtime->calls);
        $export = ParametricCampaignExporter::export($loaded['outputPath']);
        self::assertSame(2, $export['directions']);
        $rows = array_map('str_getcsv', file($export['path']));
        $header = array_shift($rows);
        $row = array_combine($header, $rows[0]);
        self::assertSame('3', $row['samples']);
        self::assertSame('2', $row['mean_rounds']);
    }

    public function testExporterKeepsCampLabelsWhenAttackerAndDefenderSwap(): void
    {
        $plan = $this->plan();
        $plan['sampling'] = ['repetitions' => 3, 'baseSeed' => 42, 'batchSize' => 2];
        $files = $this->writePlan($plan);
        $loaded = ParametricCampaign::load($files['plan']);
        (new ParametricCampaignRunner(new FakeCampaignRuntime(), __FILE__))->run($loaded);
        foreach (glob($loaded['outputPath'].'/lots/*.json') as $file) {
            $lot = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $n = $lot['iterations'];
            foreach ($lot['response']['scenarios'] as &$scenario) {
                $result = &$scenario['result'];
                $result['attackerRawDeathsByType'][0] = 2 * $n;
                $result['defenderRawDeathsByType'][0] = 3 * $n;
                $result['attackerRawWoundedByType'][0] = 4 * $n;
                $result['defenderRawWoundedByType'][0] = 5 * $n;
                $result['attackerProjectedByType'][0] = [7 * $n, 1 * $n, 2 * $n, 0];
                $result['defenderProjectedByType'][0] = [4 * $n, 2 * $n, 3 * $n, 1 * $n];
                unset($result);
            }
            unset($scenario);
            file_put_contents($file, json_encode($lot, JSON_THROW_ON_ERROR));
        }
        $export = ParametricCampaignExporter::export($loaded['outputPath']);
        $csv = array_map('str_getcsv', file($export['path']));
        $header = array_shift($csv);
        $rows = [];
        foreach ($csv as $values) {
            $row = array_combine($header, $values);
            $rows[$row['attacker']] = $row;
        }
        self::assertSame('3', $rows['A']['samples']);
        self::assertSame('3', $rows['B']['samples']);
        foreach (['A' => ['A', 'B'], 'B' => ['B', 'A']] as $attacker => [$attackingCamp,$defendingCamp]) {
            $row = $rows[$attacker];
            self::assertSame('2', $row[$attackingCamp.'_raw_dead_soldier']);
            self::assertSame('4', $row[$attackingCamp.'_raw_wounded_soldier']);
            self::assertSame('2', $row[$attackingCamp.'_projected_dead_soldier']);
            self::assertSame('1', $row[$attackingCamp.'_projected_wounded_soldier']);
            self::assertSame('0', $row[$attackingCamp.'_projected_prisoners_soldier']);
            self::assertSame('0.3', $row[$attackingCamp.'_bench_economic_loss_rate']);
            self::assertSame('3', $row[$defendingCamp.'_raw_dead_soldier']);
            self::assertSame('5', $row[$defendingCamp.'_raw_wounded_soldier']);
            self::assertSame('3', $row[$defendingCamp.'_projected_dead_soldier']);
            self::assertSame('2', $row[$defendingCamp.'_projected_wounded_soldier']);
            self::assertSame('1', $row[$defendingCamp.'_projected_prisoners_soldier']);
            self::assertSame('0.5', $row[$defendingCamp.'_bench_economic_loss_rate']);
        }
    }

    public function testPreviewAcceptsTwoThousandRepetitionsPerDirection(): void
    {
        $plan = $this->plan();
        $plan['sampling'] = ['repetitions' => 2000, 'baseSeed' => 42, 'batchSize' => 100];
        $plan['limits']['maxCombats'] = 4000;
        $files = $this->writePlan($plan);
        $loaded = ParametricCampaign::load($files['plan']);
        $preview = ParametricCampaign::preview($loaded['plan'], $loaded['profile']);
        self::assertSame(1, $preview['experiments']);
        self::assertSame(2, $preview['directions']);
        self::assertSame(4000, $preview['combats']);
    }

    public function testOptimizedCampaignRequiresSharedWeatherAndUsesDistinctProtocol(): void
    {
        $plan = $this->plan();
        $plan['stochasticEngineVersion'] = ParametricCampaign::OPTIMIZED_STOCHASTIC_VERSION;
        $plan['scenarios'][0]['weather'] = ['A' => 'wind', 'B' => 'wind'];
        $loaded = ParametricCampaign::load($this->writePlan($plan)['plan']);
        $experiment = iterator_to_array(ParametricCampaign::experiments($loaded['plan'], $loaded['profile']))[0];
        $request = ParametricCampaign::batchRequest($experiment, 42, 0, 1, 1, $plan['stochasticEngineVersion']);
        self::assertSame(ParametricCampaign::OPTIMIZED_STOCHASTIC_VERSION, $request['stochasticEngineVersion']);
        self::assertSame('wind', $experiment['weather']['A']);
        self::assertSame('wind', $experiment['weather']['B']);

        $plan['scenarios'][0]['weather']['B'] = 'neutral';
        $this->expectExceptionMessage('Météo différente entre A et B');
        ParametricCampaign::load($this->writePlan($plan)['plan']);
    }

    public function testOptimizedCampaignRejectsRoundThirtyAndWeatherEffectAxes(): void
    {
        $plan = $this->plan();
        $plan['stochasticEngineVersion'] = ParametricCampaign::OPTIMIZED_STOCHASTIC_VERSION;
        $plan['axes'] = ['rounds' => ['path' => 'combat.maxRounds', 'values' => [20, 30]]];
        try {
            ParametricCampaign::load($this->writePlan($plan)['plan']);
            self::fail('A 30-round optimized plan was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('20 rounds', $error->getMessage());
        }
        $plan['axes'] = ['weather' => ['path' => 'weather.wind.archer.attack', 'values' => ['0.5']]];
        $this->expectExceptionMessage('effets météo ne sont pas configurables');
        ParametricCampaign::load($this->writePlan($plan)['plan']);
    }

    private function plan(): array
    {
        return['schemaVersion' => ParametricCampaign::SCHEMA, 'profile' => 'profile.json', 'output' => 'out', 'sampling' => ['repetitions' => 2, 'baseSeed' => 42, 'batchSize' => 1], 'limits' => ['maxCombats' => 1000], 'axes' => [], 'crosses' => ['singles' => true, 'pairs' => [], 'triplets' => []], 'scenarios' => [['id' => 's', 'directions' => 'both', 'weather' => ['A' => 'neutral', 'B' => 'neutral'], 'modifiers' => ['A' => [], 'B' => []], 'armies' => ['A' => ['mode' => 'explicit', 'units' => ['soldier' => 10]], 'B' => ['mode' => 'explicit', 'units' => ['soldier' => 10]]], 'compositionVariants' => [['id' => 'reference', 'operations' => []]]]]];
    }
    private function writePlan(array$plan): array
    {
        $dir = sys_get_temp_dir().'/waar-parametric-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->paths[] = $dir;
        file_put_contents($dir.'/profile.json', json_encode(EngineProfile::defaults(), JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        return['plan' => $dir.'/plan.json'];
    }
    private static function remove(string$path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class FakeCampaignRuntime implements CohortRuntime
{
    public int $calls = 0;
    public function resolve(array$request): array
    {
        throw new \LogicException();
    }
    public function provenance(): array
    {
        return['kind' => 'fake', 'transport' => 'memory', 'modelVersion' => 'waar-cohort-v2'];
    }
    public function batch(array$request): array
    {
        $this->calls++;
        $rows = [];
        foreach ($request['scenarios'] as $s) {
            $zero = [0, 0, 0, 0];
            $projected = array_fill(0, 4, [0, 0, 0, 0]);
            $rows[] = ['id' => $s['id'], 'armyIdentities' => $s['armyIdentities'], 'result' => ['samples' => $request['iterations'], 'attackerWins' => $request['iterations'], 'defenderWins' => 0, 'draws' => 0, 'roundSum' => $request['iterations'] * 2, 'attackerInitialByType' => array_values(array_replace(array_fill_keys(ParametricCampaign::TYPES, 0), $s['attacker']['units'])), 'defenderInitialByType' => array_values(array_replace(array_fill_keys(ParametricCampaign::TYPES, 0), $s['defender']['units'])), 'attackerRawDeathsByType' => $zero, 'defenderRawDeathsByType' => $zero, 'attackerRawWoundedByType' => $zero, 'defenderRawWoundedByType' => $zero, 'attackerProjectedByType' => $projected, 'defenderProjectedByType' => $projected]];
        }
        return['schemaVersion' => 'waar-combat-batch-result/2', 'modelVersion' => 'waar-cohort-v2', 'unitOrder' => ParametricCampaign::TYPES, 'projectedCategoryOrder' => ['healthy', 'wounded', 'dead', 'prisoners'], 'iterations' => $request['iterations'], 'startIteration' => $request['startIteration'], 'iterationRange' => ['start' => $request['startIteration'], 'endExclusive' => $request['startIteration'] + $request['iterations'], 'total' => $request['totalIterations'], 'complete' => false], 'totalCombats' => $request['iterations'] * count($rows), 'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION, 'consequenceProvenance' => ['policyVersion' => CohortRequestFactory::POLICY_VERSION, 'samplingProtocol' => CohortRequestFactory::SAMPLING_PROTOCOL, 'compressionPercent' => $request['consequences']['compressionPercent'], 'capturePercent' => $request['consequences']['capturePercent']], 'scenarios' => $rows];
    }
}
