<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\EraSimulator;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarEraSimulatorTest extends TestCase
{
    public function testSameSeedAndProfileProduceIdenticalFramesAndCombatArchive(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $accounts = [
            ['id' => 'rage', 'policy' => 'rageux'], ['id' => 'frog', 'policy' => 'grenouille', 'soldierParadigm' => true],
            ['id' => 'lift', 'policy' => 'ascenseur'], ['id' => 'farm', 'policy' => 'fermier'],
            ['id' => 'script', 'policy' => 'scripteur'],
        ];
        $first = $simulator->advance($simulator->start(42, 4, $accounts), 4);
        $second = $simulator->advance($simulator->start(42, 4, $accounts), 4);
        self::assertSame($first, $second);
        self::assertCount(4, $first['frames']);
        self::assertNotEmpty($first['combats']);
        self::assertSame(4, $first['tick']);
        foreach ($first['frames'] as $frame) {
            self::assertCount(5, $frame['points']);
            self::assertContains($frame['weather'], EngineProfile::WEATHER);
        }
        foreach ($first['combats'] as $archive) {
            self::assertSame(EraSimulator::COMBAT_ARCHIVE_FORMAT, $archive['format']);
            $combat = EraSimulator::decodeCombat($archive);
            self::assertSame($combat['request']['attacker']['modifiers'], $combat['request']['defender']['modifiers']);
        }
    }

    public function testVillageAppearsAsPointAtItsGloryTierAndPresetArmyValue(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(12, 1, [['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'farm', 'policy' => 'fermier']]);
        $state['players']['frog']['glory'] = 60;
        $state = $simulator->advance($state);
        $village = $state['villages']['village-20'];
        $point = $state['frames'][0]['villages'][0];
        self::assertSame('village-20', $point['id']);
        self::assertSame('village', $point['kind']);
        self::assertSame(20, $point['glory']);
        self::assertSame($village['army'], $point['army']);
        self::assertSame($village['goldMax'], $point['goldMax']);
        self::assertSame($village['goldRefill'], $point['goldRefill']);
        self::assertSame(\Waar\MicroCombat\Bagaar\HostRules::armyValue($village['army'], $profile->costs()), $point['armyGold']);
    }

    public function testFarmerCanSpyVillageBeforeChoosingAnAttack(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(12, 1, [['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'farm', 'policy' => 'fermier']]);
        $state['players']['frog']['glory'] = 60;
        $state['players']['farm']['glory'] = 20;
        $state['players']['farm']['gold'] = 20000;
        $state['players']['farm']['army']['soldier'] = 200;
        $state = $simulator->advance($state);
        self::assertSame(30, $state['manifest']['spyRange']);
        self::assertSame(1, $state['players']['farm']['spies']['village-20']['tick']);
        self::assertGreaterThan(0, $state['players']['farm']['spies']['village-20']['armyTotal']);
    }

    public function testVillageDefeatStopsFurtherAttacksUntilArmyRecovers(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime(false, 'defender'));
        $state = $simulator->start(12, 1, [['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'farm', 'policy' => 'fermier']]);
        $state['players']['frog']['glory'] = 60;
        $state['players']['farm']['glory'] = 20;
        $state['players']['farm']['army']['soldier'] = 100;
        $state['players']['farm']['spies']['village-20'] = ['tick' => 1, 'armyTotal' => 1, 'gold' => 1000, 'glory' => 20, 'morale' => 'high'];
        $state = $simulator->advance($state);
        $villageCombats = array_values(array_filter($state['events'],
            static fn (array $event): bool => ($event['type'] ?? null) === 'combat' && ($event['defender'] ?? null) === 'village-20'));
        self::assertCount(1, $villageCombats);
        self::assertGreaterThanOrEqual(8000, $state['players']['farm']['villageFailures']['village-20']);
    }

    public function testOfficePlayerActsOnlyDuringPlayHoursWhileMineKeepsProducing(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(18, 100, [
            ['id' => 'office', 'name' => 'Alice', 'policy' => 'grenouille', 'activity' => 'office', 'aggressionPercent' => 80],
            ['id' => 'always', 'name' => 'Bob', 'policy' => 'grenouille', 'activity' => 'all-day'],
        ]);
        $state = $simulator->advance($state);
        self::assertSame(0, $state['players']['office']['mineLevel']);
        self::assertSame(1, $state['players']['always']['mineLevel']);
        $state = $simulator->advance($state, 9);
        self::assertGreaterThan(0, $state['players']['office']['mineLevel']);
        $state = $simulator->advance($state, 7);
        $before = $state['players']['office']['gold'];
        $production = \Waar\MicroCombat\Bagaar\HostRules::mineProduction($state['players']['office']['mineLevel']);
        $state = $simulator->advance($state);
        self::assertSame($before + $production, $state['players']['office']['gold']);
        self::assertSame('Alice', $state['frames'][17]['points'][1]['name']);
    }

    public function testPlayerCombatRecordMatchesResolvedCombats(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->advance($simulator->start(22, 2, [
            ['id' => 'a', 'policy' => 'rageux'], ['id' => 'b', 'policy' => 'rageux'],
        ]), 2);
        $records = array_column($state['players'], 'record');
        self::assertNotEmpty($state['combats']);
        self::assertSame(count($state['combats']), array_sum(array_column($records, 'wins')));
        self::assertSame(count($state['combats']), array_sum(array_column($records, 'losses')));
        self::assertSame($state['players']['a']['record'], $state['frames'][1]['points'][0]['record']);
    }

    public function testTickChunksDoNotChangeTheEra(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $accounts = [['id' => 'a', 'policy' => 'rageux'], ['id' => 'b', 'policy' => 'fermier']];
        $oneShot = $simulator->advance($simulator->start(17, 3, $accounts), 3);
        $chunks = $simulator->start(17, 3, $accounts);
        for ($i = 0; $i < 3; $i++) {
            $chunks = $simulator->advance($chunks);
        }
        self::assertSame($oneShot, $chunks);
    }

    public function testDetachingTheCombatArchivePreservesFutureSeedsAndFrames(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $accounts = [['id' => 'a', 'policy' => 'rageux'], ['id' => 'b', 'policy' => 'fermier']];
        $full = $simulator->advance($simulator->start(23, 3, $accounts));
        $earlyCombats = count($full['combats']);
        $detached = $full;
        $detached['archiveDetached'] = true;
        $detached['combats'] = [];
        $full = $simulator->advance($full, 2);
        $detached = $simulator->advance($detached, 2);
        self::assertSame($full['frames'], $detached['frames']);
        self::assertSame($full['events'], $detached['events']);
        self::assertSame($full['combatCount'], $detached['combatCount']);
        self::assertSame(array_slice($full['combats'], $earlyCombats), $detached['combats']);
    }

    public function testRwaaRequiresStrictLeadForTwentyFourCompletedTicks(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $accounts = [['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'script', 'policy' => 'scripteur']];
        $tied = $simulator->start(5, 25, $accounts);
        $tied['players']['frog']['glory'] = 50;
        $tied['players']['script']['glory'] = 50;
        self::assertNull($simulator->advance($tied)['candidate']);
        $state = $simulator->start(5, 25, $accounts);
        $state['players']['frog']['glory'] = 50;
        $state = $simulator->advance($state, 24);
        self::assertSame('frog', $state['candidate']);
        self::assertSame(23, $state['candidateHours']);
        self::assertNull($state['rwaa']);
        $state = $simulator->advance($state);
        self::assertSame('frog', $state['rwaa']);
        self::assertSame(20, $state['rwaaPv']);
    }

    public function testAscenseurCanSurrenderAfterNineDefensiveLossesInTheEra(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(3, 12, [['id' => 'rage', 'policy' => 'rageux'], ['id' => 'lift', 'policy' => 'ascenseur']]);
        $state['players']['lift']['cyclePhase'] = 'surrender';
        $state = $simulator->advance($state, 12);
        self::assertGreaterThanOrEqual(1, $state['players']['lift']['surrenders']);
        self::assertContains(true, array_column(array_filter($state['events'], static fn (array $event): bool => $event['type'] === 'combat'), 'surrender'));
    }

    public function testScripteurHealsImmediatelyAfterHisCombat(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime(true));
        $state = $simulator->start(8, 4, [['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'script', 'policy' => 'scripteur']]);
        $state['players']['script']['army']['soldier'] = 100;
        $state['players']['script']['hospitalLevel'] = 1;
        $state['players']['script']['gold'] = 10000;
        $state['players']['frog']['army']['soldier'] = 1;
        $state['players']['frog']['gold'] = 10000;
        $state['players']['script']['spies']['frog'] = ['tick' => 0, 'gold' => 10000, 'armyTotal' => 1, 'glory' => 0, 'morale' => 'high'];
        $state = $simulator->advance($state);
        $combatIndex = array_search('combat', array_column($state['events'], 'type'), true);
        self::assertNotFalse($combatIndex);
        self::assertSame('heal', $state['events'][$combatIndex + 1]['type']);
        self::assertSame('script', $state['events'][$combatIndex + 1]['actor']);
        self::assertSame(0, $state['players']['script']['hospital']['soldier']);
    }
}

final class BagaarFakeRuntime implements CohortRuntime
{
    public function __construct(private readonly bool $woundAttacker = false, private readonly string $winner = 'attacker')
    {
    }

    public function resolve(array $request): array
    {
        $sides = [];
        foreach (['attacker', 'defender'] as $side) {
            $types = [];
            foreach (EngineProfile::UNIT_COSTS as $type => $cost) {
                $count = $request[$side]['units'][$type] ?? 0;
                $wounded = $this->woundAttacker && $side === 'attacker' && $type === 'soldier' && $count > 1 ? 1 : 0;
                $types[$type] = ['initial' => $count, 'projected' => [
                    'healthy' => $count - $wounded, 'wounded' => $wounded, 'dead' => 0, 'prisoners' => 0]];
            }
            $sides[$side] = ['types' => $types];
        }
        return ['result' => ['schemaVersion' => 'waar-combat-result/2', 'winner' => $this->winner,
            'replayHash' => hash('sha256', (string)$request['seed']),
            'snapshot' => ['stochasticEngineVersion' => $request['stochasticEngineVersion'], 'armyIdentities' => $request['armyIdentities']]],
            'consequences' => ['schemaVersion' => 'waar-combat-consequences/1',
                'policyVersion' => CohortRequestFactory::POLICY_VERSION,
                'samplingProtocol' => CohortRequestFactory::SAMPLING_PROTOCOL,
                'compressionPercent' => $request['consequences']['compressionPercent'],
                'capturePercent' => $request['consequences']['capturePercent'], ...$sides]];
    }

    public function batch(array $request): array
    {
        throw new \LogicException('Bagaar résout des combats individuels.');
    }

    public function provenance(): array
    {
        return ['kind' => 'fake', 'transport' => 'in-process', 'modelVersion' => EngineProfile::MODEL_VERSION];
    }
}
