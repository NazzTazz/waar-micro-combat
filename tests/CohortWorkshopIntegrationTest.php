<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\ConsequenceObjectives;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\EngineProfileMigrator;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;

require_once dirname(__DIR__).'/autoload.php';

final class CohortWorkshopIntegrationTest extends TestCase
{
    public function testNativeEngineRejectsStrikesAboveTenAfterModifiers(): void
    {
        $profile = EngineProfile::defaults();
        $profile['combat']['maxRounds'] = 1;
        $profile['units']['soldier']['strikesPerAttack'] = 10;
        $request = [
            'profile' => $profile,
            'armies' => ['A' => ['soldier' => 1], 'B' => ['soldier' => 1]],
            'weather' => ['A' => 'neutral', 'B' => 'neutral'],
            'seed' => 42,
        ];
        self::assertCount(2, (new DuelService())->simulate($request)['directions']);

        $request['modifiers'] = ['A' => [[
            'source' => 'training', 'id' => 'double-strikes', 'label' => 'Double frappes',
            'unitType' => 'soldier', 'parameter' => 'strikesPerAttack',
            'operation' => 'multiply', 'value' => '2',
        ]], 'B' => []];
        $this->expectExceptionMessage('prepared value outside supported range');
        (new DuelService())->simulate($request);
    }

    public function testCompleteBatchParityIncludesOptionalCanonicalClassificationProvenance(): void
    {
        $values = EngineProfile::defaults();
        $values['combat']['maxRounds'] = 1;
        $batch = (new CohortRequestFactory())->monotypes(EngineProfile::fromArray($values), 'neutral', 42, 1);
        $batch['scenarios'] = array_slice($batch['scenarios'], 0, 1);
        $php = new ProcessCohortRuntime(null, 'php');
        $rust = new ProcessCohortRuntime();
        $canonical = static function (array $value) use (&$canonical): array {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }
            unset($item);
            if (!array_is_list($value)) {
                ksort($value);
            }
            return $value;
        };
        foreach ([null, '0', '0.200000', '1'] as $threshold) {
            unset($batch['ruleset']['woundDamageThreshold']);
            if ($threshold !== null) {
                $batch['ruleset']['woundDamageThreshold'] = $threshold;
            }
            $actual = $php->batch($batch);
            $expected = $rust->batch($batch);
            self::assertSame($canonical($expected), $canonical($actual));
            if ($threshold === null) {
                self::assertArrayNotHasKey('classificationProvenance', $actual);
            } else {
                self::assertSame(['woundDamageThreshold' => $threshold === '0.200000' ? '0.2' : $threshold], $actual['classificationProvenance']);
            }
        }
    }

    public function testOldConsequenceContextsCannotReuseObjectives(): void
    {
        $values = EngineProfile::defaults();
        $profile = EngineProfile::fromArray($values);
        $context = ['weather' => 'neutral', 'baseSeed' => 42, 'iterations' => 1, 'budget' => MonotypeMeasurementService::BUDGET,
            'objectiveMetric' => 'rawCasualtyRatio', 'modelVersion' => EngineProfile::MODEL_VERSION, 'rulesetVersion' => $profile->ruleset()['version'],
            'runtime' => ['kind' => 'rust', 'transport' => 'process-jsonl', 'modelVersion' => EngineProfile::MODEL_VERSION],
            'consequences' => CohortRequestFactory::consequenceContext($profile)];
        $zones = [];
        foreach (array_keys(EngineProfile::UNIT_COSTS) as $a) {
            foreach (array_keys(EngineProfile::UNIT_COSTS) as $b) {
                foreach (['attacker', 'defender'] as $side) {
                    $zones[] = ['id' => "$a-vs-$b/$side", 'center' => ['x' => .5, 'y' => .1], 'radii' => ['x' => .05, 'y' => .1],
                        'sourceFingerprint' => $profile->semanticFingerprint(), 'modelVersion' => EngineProfile::MODEL_VERSION, 'context' => $context];
                }
            }
        }
        $validator = new ConsequenceObjectives();
        self::assertSame($zones, $validator->validate($values, $zones, 'neutral', 42, 1));
        foreach ([[], ['policyVersion' => 'wounded-capture-then-compress/2', 'samplingProtocol' => 'floor/1'],
            ['policyVersion' => CohortRequestFactory::POLICY_VERSION, 'samplingProtocol' => 'unknown']] as $old) {
            $obsolete = $zones;
            foreach ($obsolete as &$zone) {
                $zone['context']['consequences'] = [
                    'lossCompressionPercent' => $profile->lossCompressionPercent, 'capturePercent' => $profile->capturePercent, ...$old];
            }
            unset($zone);
            try {
                $validator->validate($values, $obsolete, 'neutral', 42, 1);
                self::fail('Obsolete context accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('contexte de mesure', $error->getMessage());
            }
        }
        self::assertSame($values, $profile->toArray());
    }
    public function testConsequenceExecutionChoiceDoesNotMutateProfilesAndRejectsOldRuntime(): void
    {
        $values = EngineProfile::defaults();
        $profile = EngineProfile::fromArray($values);
        $snapshot = $profile->toArray();
        $fingerprint = $profile->semanticFingerprint();
        $factory = new CohortRequestFactory();
        $duel = $factory->combat($profile, ['soldier' => 3], ['knight' => 18], 42, 'neutral', 'neutral');
        $batch = $factory->monotypes($profile, 'neutral', 42, 1);
        self::assertSame(CohortRequestFactory::POLICY_VERSION, $duel['consequences']['policyVersion']);
        self::assertSame($duel['consequences'], $batch['consequences']);
        self::assertSame($snapshot, $profile->toArray());
        self::assertSame($fingerprint, $profile->semanticFingerprint());
        foreach ([[], ['policyVersion' => 'wounded-capture-then-compress/2', 'samplingProtocol' => 'floor/1'], ['policyVersion' => CohortRequestFactory::POLICY_VERSION, 'samplingProtocol' => 'unknown']] as $old) {
            try {
                CohortRequestFactory::assertProvenance($old, $duel['consequences']);
                self::fail('Obsolete runtime accepted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('incompatible', $e->getMessage());
            }
        }
    }
    public function testLegacyMigrationIsExplicitAndPreservesUserChoices(): void
    {
        $legacy = EngineProfile::defaults();
        $legacy['schemaVersion'] = EngineProfile::LEGACY_SCHEMA_VERSION;
        foreach ($legacy['units'] as &$unit) {
            unset($unit['baseAccuracy'],$unit['accuracySpread'],$unit['strikesPerAttack']);
        }
        unset($unit);
        $legacy['weather'] = array_intersect_key($legacy['weather'], array_flip(['neutral', 'rain', 'snow', 'heat']));
        foreach ($legacy['weather'] as &$row) {
            foreach ($row as &$cell) {
                $cell = $cell['attack'];
            }
        }
        unset($row,$cell);
        $legacy['units']['archer']['attack'] = '61';
        $legacy['units']['archer']['cost'] = 71;
        $legacy['weather']['rain']['archer'] = '0.7';
        $legacy['combat'] = ['maxRounds' => 7, 'randomSpread' => '0.1', 'tieBreakPolicy' => 'draw', 'lossCompressionPercent' => 8, 'capturePercent' => 3];
        $result = (new EngineProfileMigrator())->migrate($legacy);
        $profile = $result['profile'];
        self::assertTrue($result['migration']['performed']);
        self::assertTrue($result['migration']['measurementsObsolete']);
        self::assertSame('61', $profile['units']['archer']['attack']);
        self::assertSame(71, $profile['units']['archer']['cost']);
        self::assertSame('0.15', $profile['units']['archer']['baseAccuracy']);
        self::assertSame('0', $profile['units']['archer']['accuracySpread']);
        self::assertSame(1, $profile['units']['archer']['strikesPerAttack']);
        self::assertSame('0.7', $profile['weather']['rain']['archer']['attack']);
        self::assertSame('1', $profile['weather']['rain']['archer']['baseAccuracy']);
        self::assertSame('draw', $profile['combat']['equalityPolicy']);
        self::assertContains('combat.randomSpread', $result['migration']['obsoleteFields']);
        self::assertSame('0', $profile['combat']['woundDamageThreshold']);
        self::assertContains('unsupported_schema', array_column(EngineProfile::validate($legacy), 'code'));
    }

    public function testNewAndPreThresholdProfilesUseDifferentExplicitDefaults(): void
    {
        $new = EngineProfile::defaults();
        self::assertSame('0.2', $new['combat']['woundDamageThreshold']);
        $old = $new;
        unset($old['combat']['woundDamageThreshold']);
        $migration = (new EngineProfileMigrator())->migrate($old);
        self::assertTrue($migration['migration']['performed']);
        self::assertTrue($migration['migration']['measurementsObsolete']);
        self::assertSame('0', $migration['profile']['combat']['woundDamageThreshold']);
        $explicitZero = $new;
        $explicitZero['combat']['woundDamageThreshold'] = '0';
        $unchanged = (new EngineProfileMigrator())->migrate($explicitZero);
        self::assertFalse($unchanged['migration']['performed']);
        self::assertSame('0', $unchanged['profile']['combat']['woundDamageThreshold']);
        self::assertSame('0.2', EngineProfile::fromArray($new)->ruleset()['woundDamageThreshold']);
        foreach (['-0.000001', '1.000001'] as $invalid) {
            $candidate = $new;
            $candidate['combat']['woundDamageThreshold'] = $invalid;
            $errors = EngineProfile::validate($candidate);
            self::assertContains('combat.woundDamageThreshold', array_column($errors, 'path'));
        }
    }

    public function testNativeDuelUsesDistinctCampModifiersAndExposesReplayableCohortReport(): void
    {
        $profile = EngineProfile::defaults();
        $profile['weather']['wind']['archer']['baseAccuracy'] = '0.8';
        $modifier = ['source' => 'training', 'id' => 'drill', 'label' => 'Entraînement', 'unitType' => 'archer', 'parameter' => 'baseAccuracy', 'operation' => 'multiply', 'value' => '1.2'];
        $result = (new DuelService())->simulate(['requestId' => 'native', 'profile' => $profile, 'armies' => ['A' => ['archer' => 100], 'B' => ['soldier' => 200]], 'weather' => ['A' => 'wind', 'B' => 'neutral'], 'modifiers' => ['A' => [$modifier], 'B' => []], 'seed' => 42]);
        self::assertSame('waar-cohort-v2', $result['modelVersion']);
        self::assertSame('rust', $result['runtime']['kind']);
        self::assertCount(2, $result['directions']);
        $direction = $result['directions'][0];
        self::assertSame('waar-combat-result/2', $direction['result']['schemaVersion']);
        self::assertNotEmpty($direction['result']['replayHash']);
        $archer = array_values(array_filter($direction['result']['snapshot']['prepared']['attacker']['units'], static fn (array $unit): bool => $unit['type'] === 'archer'))[0];
        self::assertSame('0.144', $archer['baseAccuracy']);
        self::assertSame(['training', 'weather'], array_column($archer['effects'], 'source'));
        self::assertArrayHasKey('matrix', $direction['result']['rounds'][0]['attackerAction']);
        self::assertCount(4, $direction['result']['rounds'][0]['attackerAction']['matrix']);
        self::assertSame(CohortRequestFactory::POLICY_VERSION, $direction['consequences']['policyVersion']);
        self::assertSame(CohortRequestFactory::SAMPLING_PROTOCOL, $direction['consequences']['samplingProtocol']);
    }

    public function testPhpDiagnosticRuntimeUsesTheSameVersionedBoundary(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $request = (new CohortRequestFactory())->combat($profile, ['soldier' => 10], ['archer' => 5], 42, 'neutral', 'neutral');
        self::assertSame(CohortRequestFactory::POLICY_VERSION, $request['consequences']['policyVersion']);
        $runtime = new ProcessCohortRuntime(null, 'php');
        $result = $runtime->resolve($request);
        self::assertSame('php', $runtime->provenance()['kind']);
        self::assertSame('waar-combat-result/2', $result['result']['schemaVersion']);
        self::assertSame('waar-cohort-v2', $result['result']['modelVersion']);
        $php = (new MonotypeMeasurementService($runtime))->measure($profile->toArray(), 'neutral', 42, 1);
        $rust = (new MonotypeMeasurementService())->measure($profile->toArray(), 'neutral', 42, 1);
        self::assertSame($rust['rows'], $php['rows']);
        self::assertSame($rust['context']['consequences'], $php['context']['consequences']);
        self::assertSame(CohortRequestFactory::POLICY_VERSION, $php['context']['consequences']['policyVersion']);
        self::assertSame($profile->semanticFingerprint(), $php['profileFingerprint']);
        self::assertSame('php', $php['context']['runtime']['kind']);
    }
}
