<?php

declare(strict_types=1);

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Exploration\ParametricCampaign;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

require_once dirname(__DIR__).'/autoload.php';

final class ParametricCampaignNativeTest extends TestCase
{
    public function testPersistentWebBatchStillRejectsTotalAboveOneHundred(): void
    {
        $request = ParametricCampaign::batchRequest($this->experiment($this->profile()), 42, 0, 1, 120);
        $request['schemaVersion'] = 'waar-combat-batch-request/2';
        $this->expectException(\InvalidArgumentException::class);
        (new ProcessCohortRuntime())->batch($request);
    }

    public function testPreparationMatchesWebSummaryBoundary(): void
    {
        $profile = $this->profile();
        $experiment = $this->experiment($profile);
        $capture = new CapturingBatchRuntime();
        (new DuelService($capture))->simulate(['profile' => $profile, 'armies' => ['A' => $experiment['armies']['A']['units'], 'B' => $experiment['armies']['B']['units']], 'weather' => $experiment['weather'], 'modifiers' => $experiment['modifiers'], 'seed' => 42], true);
        $explorer = ParametricCampaign::batchRequest($experiment, 42, 0, 50, 50);
        $web = $capture->request;
        self::assertSame($web['ruleset'], $explorer['ruleset']);
        self::assertSame($web['consequences'], $explorer['consequences']);
        self::assertSame($web['scenarios'], $explorer['scenarios']);
        self::assertSame($web['baseSeed'], $explorer['baseSeed']);
    }

    public function testNativeContinuousBatchEqualsTwoLots(): void
    {
        $profile = $this->profile();
        $experiment = $this->experiment($profile);
        $runtime = new ProcessCohortRuntime(null, null, true);
        $full = $runtime->batch(ParametricCampaign::batchRequest($experiment, 42, 0, 4, 4));
        $first = $runtime->batch(ParametricCampaign::batchRequest($experiment, 42, 0, 2, 4));
        $second = $runtime->batch(ParametricCampaign::batchRequest($experiment, 42, 2, 2, 4));
        foreach ($full['scenarios'] as $i => $scenario) {
            $expected = $scenario['result'];
            $actual = $first['scenarios'][$i]['result'];
            foreach ($second['scenarios'][$i]['result'] as $key => $value) {
                if (in_array($key, ['attackerInitialByType', 'defenderInitialByType'], true)) {
                    continue;
                }
                if (is_int($value)) {
                    $actual[$key] += $value;
                } elseif (is_array($value)) {
                    $actual[$key] = $this->sum($actual[$key], $value);
                }
            }
            self::assertSame($expected, $actual);
        }
    }

    private function profile(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/experiments/campagne-coeur/reference-profile.json'), true, 128, JSON_THROW_ON_ERROR);
    }
    private function experiment(array$profile): array
    {
        $p = EngineProfile::fromArray($profile);
        $unitsA = ['soldier' => 20, 'spearman' => 2, 'archer' => 3, 'knight' => 1];
        $unitsB = ['soldier' => 15, 'spearman' => 3, 'archer' => 2, 'knight' => 1];
        $meta = static fn (array$u, array$c) => ['mode' => 'explicit', 'units' => $u, 'targetBudget' => null, 'actualBudget' => array_sum(array_map(fn ($t, $n) => $c[$t] * $n, array_keys($u), $u)), 'remainder' => 0];
        return['id' => 'native', 'scenarioId' => 'native', 'compositionId' => 'reference', 'axes' => [], 'profile' => $profile, 'profileFingerprint' => $p->semanticFingerprint(), 'armies' => ['A' => $meta($unitsA, $p->costs()), 'B' => $meta($unitsB, $p->costs())], 'weather' => ['A' => 'neutral', 'B' => 'wind'], 'modifiers' => ['A' => [], 'B' => []], 'directions' => 'both'];
    }
    private function sum(array$a, array$b): array
    {
        foreach ($b as $i => $v) {
            $a[$i] = is_array($v) ? $this->sum($a[$i], $v) : $a[$i] + $v;
        }
        return$a;
    }
}

final class CapturingBatchRuntime implements CohortRuntime
{
    public array$request = [];
    public function resolve(array$request): array
    {
        throw new \LogicException();
    }
    public function provenance(): array
    {
        return['kind' => 'capture', 'transport' => 'memory', 'modelVersion' => 'waar-cohort-v2'];
    }
    public function batch(array$request): array
    {
        $this->request = $request;
        $rows = [];
        foreach ($request['scenarios'] as $s) {
            $rows[] = ['id' => $s['id'], 'armyIdentities' => $s['armyIdentities'], 'result' => ['samples' => 50, 'attackerWins' => 50, 'defenderWins' => 0, 'draws' => 0, 'roundSum' => 50, 'attackerInitialByType' => [20, 2, 3, 1], 'defenderInitialByType' => [15, 3, 2, 1], 'attackerRawDeathsByType' => [0, 0, 0, 0], 'defenderRawDeathsByType' => [0, 0, 0, 0], 'attackerRawWoundedByType' => [0, 0, 0, 0], 'defenderRawWoundedByType' => [0, 0, 0, 0], 'attackerProjectedByType' => array_fill(0, 4, [0, 0, 0, 0]), 'defenderProjectedByType' => array_fill(0, 4, [0, 0, 0, 0])]];
        }
        return['schemaVersion' => 'waar-combat-batch-result/2', 'modelVersion' => 'waar-cohort-v2', 'unitOrder' => ParametricCampaign::TYPES, 'projectedCategoryOrder' => ['healthy', 'wounded', 'dead', 'prisoners'], 'iterations' => 50, 'startIteration' => 0, 'totalCombats' => 100, 'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION, 'consequenceProvenance' => ['policyVersion' => CohortRequestFactory::POLICY_VERSION, 'samplingProtocol' => CohortRequestFactory::SAMPLING_PROTOCOL, 'compressionPercent' => $request['consequences']['compressionPercent'], 'capturePercent' => $request['consequences']['capturePercent']], 'scenarios' => $rows];
    }
}
