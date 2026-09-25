<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\{CohortRequestFactory,DuelService,EngineProfile,ProcessCohortRuntime};

require_once dirname(__DIR__).'/autoload.php';

final class WorkshopAddressedRandomTest extends TestCase
{
    private static function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
    public function testDarthCycleInBothRolesThroughTheRealServices(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/engines/waar-cohort/tests/fixtures/issue16-25.json'), true, 512, JSON_THROW_ON_ERROR);
        $rules = $fixture['report']['result']['ruleset'];
        $profile = EngineProfile::defaults();
        foreach ($rules['units'] as $unit) {
            $type = $unit['type'];
            unset($unit['type']);
            $profile['units'][$type] = $unit;
        }
        $profile['relations'] = [];
        foreach ($rules['engagements'] as $a => $row) {
            foreach ($row as $b => $value) {
                if ($a !== $b && $value['attackFactor'] !== '1') {
                    $profile['relations'][] = ['acting' => $a, 'target' => $b, 'factor' => $value['attackFactor']];
                }
            }
        }
        $profile['combat'] = [...$profile['combat'], 'maxRounds' => 2, 'surrenderEnabled' => false, 'woundDamageThreshold' => '0.5', 'tieBreakCriterion' => 'structure', 'equalityPolicy' => 'draw', 'lossCompressionPercent' => 100, 'capturePercent' => 1];
        $input = ['profile' => $profile, 'armies' => ['A' => ['archer' => 33], 'B' => ['spearman' => 50]], 'weather' => ['A' => 'neutral', 'B' => 'neutral'], 'seed' => 42];
        $native = new DuelService(new ProcessCohortRuntime(null, 'rust'));
        $php = new DuelService(new ProcessCohortRuntime(null, 'php'));
        $reports = [];
        foreach (['0.25', '0.3', '0.25'] as $p) {
            $input['profile']['units']['spearman']['baseAccuracy'] = $p;
            $result = $native->simulate($input);
            $reference = $php->simulate($input);
            self::assertSame(self::canonical($reference['directions']), self::canonical($result['directions']));
            $reports[] = $result;
            foreach ($result['directions'] as $direction) {
                self::assertSame($direction['labels']['attacker'], $direction['result']['snapshot']['armyIdentities']['attacker']);
                self::assertSame($direction['labels']['defender'], $direction['result']['snapshot']['armyIdentities']['defender']);
            }
        }
        self::assertSame($reports[0], $reports[2]);
        // B remains the same army in both roles; defensive efficiencies are 1 here.
        self::assertSame($reports[0]['directions'][0]['result']['defender'], $reports[0]['directions'][1]['result']['attacker']);
        self::assertSame($reports[0]['directions'][1]['result']['attacker'], $reports[1]['directions'][1]['result']['attacker']);
        $summary = $native->simulate($input, true);
        self::assertSame(self::canonical($php->simulate($input, true)), self::canonical($summary));
        self::assertSame(50, $summary['iterations']);
        self::assertSame(100, $summary['totalCombats']);
        self::assertSame($summary['rows'][0]['camps']['A'], $summary['rows'][1]['camps']['A']);
        self::assertSame($summary['rows'][0]['camps']['B'], $summary['rows'][1]['camps']['B']);
    }

    public function testRuntimeCannotReturnAnotherProtocolOrSwapArmyIdentities(): void
    {
        foreach ([
            ['stochasticEngineVersion' => 'lcg31-binomial-normal-v1', 'armyIdentities' => ['attacker' => 'A', 'defender' => 'B']],
            ['stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION, 'armyIdentities' => ['attacker' => 'B', 'defender' => 'A']],
        ] as $wrong) {
            try {
                CohortRequestFactory::assertRandomProvenance($wrong, ['attacker' => 'A', 'defender' => 'B']);
                self::fail('Wrong random identity accepted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('incompatibles', $e->getMessage());
            }
        }
    }
}
