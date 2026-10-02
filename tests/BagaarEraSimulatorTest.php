<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\EraSimulator;
use Waar\MicroCombat\Bagaar\PlayerObservation;
use Waar\MicroCombat\Workshop\CohortRuntime;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarEraSimulatorTest extends TestCase
{
    public function testPopulationAcceptsVeryCalmAndVeryAggressivePlayers(): void
    {
        $simulator = new EraSimulator(EngineProfile::fromArray(EngineProfile::defaults()), new BagaarFakeRuntime());
        $state = $simulator->start(42, 1, [
            ['id' => 'calm', 'policy' => 'fermier', 'aggressionPercent' => 2],
            ['id' => 'furious', 'policy' => 'rageux', 'aggressionPercent' => 200],
        ]);
        self::assertSame(2, $state['players']['calm']['aggressionPercent']);
        self::assertSame(200, $state['players']['furious']['aggressionPercent']);
    }

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
            self::assertGreaterThanOrEqual(5, count($frame['points']));
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
        self::assertSame(['wins' => 0, 'draws' => 0, 'losses' => 0], $point['record']);
        self::assertSame(0, $point['goldDistributed']);
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
        self::assertTrue($state['players']['farm']['villageCautious']);
        self::assertSame(1, $state['players']['farm']['lastVillageAttackTick']);
    }

    public function testAbandonedUnarmedAccountRemainsPillageable(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(12, 1, [['id' => 'farm', 'policy' => 'fermier'], ['id' => 'fridge', 'policy' => 'casual']]);
        $state['players']['farm']['army']['soldier'] = 100;
        $state['players']['fridge']['status'] = 'abandoned';
        $state['players']['farm']['spies']['fridge'] = ['tick' => 1, 'armyTotal' => 0, 'gold' => 2000];
        $state = $simulator->advance($state);
        $combats = array_values(array_filter($state['events'], static fn (array $event): bool =>
            ($event['type'] ?? null) === 'combat' && ($event['defender'] ?? null) === 'fridge'));
        self::assertNotEmpty($combats);
        self::assertGreaterThan(0, $combats[0]['loot']);
        self::assertLessThan(2000, $state['players']['fridge']['gold']);
        $points = array_column($state['frames'][0]['points'], null, 'id');
        $totalLoot = array_sum(array_column($combats, 'loot'));
        self::assertSame($totalLoot, $points['farm']['goldLooted']);
        self::assertSame($totalLoot, $points['fridge']['goldSuffered']);
        self::assertSame($totalLoot, $points['fridge']['goldFlow']['pillaged']);
        self::assertGreaterThanOrEqual($totalLoot, $points['farm']['goldFlow']['income']);
    }

    public function testAbandonmentAddsAnEntrantWhileResetKeepsTheSameAccount(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        foreach (['abandon' => 1, 'reset' => 2] as $expected => $remainder) {
            $targetId = 'target';
            while (hexdec(substr(hash('sha256', $targetId), 0, 2)) % 4 !== $remainder) {
                $targetId .= 'x';
            }
            $state = $simulator->start(12, 24, [
                ['id' => 'farm', 'policy' => 'fermier'],
                ['id' => $targetId, 'name' => 'Alice', 'policy' => 'casual'],
            ]);
            $state['players']['farm']['army']['soldier'] = 100;
            $state['players']['farm']['spies'][$targetId] = ['tick' => 1, 'armyTotal' => 0, 'gold' => 2000];
            $state['players'][$targetId]['pauses'] = 1;
            $state['players'][$targetId]['recentCombats'] = array_fill(0, 4, ['tick' => 1, 'lost' => true]);
            $state = $simulator->advance($state);
            self::assertContains($expected, array_column($state['events'], 'type'));
            if ($expected === 'abandon') {
                self::assertCount(2, $state['players']);
                self::assertSame('abandoned', $state['players'][$targetId]['status']);
                self::assertSame(0, $state['pendingPlayers']['entrant-1']['glory']);
                self::assertNotContains('arrival', array_column($state['events'], 'type'));
                $state = $simulator->advance($state, 23);
                self::assertGreaterThan(1, $state['players']['entrant-1']['joinedTick']);
                self::assertContains('arrival', array_column($state['events'], 'type'));
            } else {
                self::assertCount(2, $state['players']);
                self::assertSame('Alice', $state['players'][$targetId]['name']);
                self::assertSame(0, $state['players'][$targetId]['glory']);
                self::assertSame(0, array_sum($state['players'][$targetId]['army']));
                self::assertSame(1, $state['players'][$targetId]['resetCount']);
                self::assertNotContains('arrival', array_column($state['events'], 'type'));
            }
        }
    }

    public function testHackerCanDriveAChosenAccountToAbandonmentThroughCombat(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $targetId = 'drawing';
        while (hexdec(substr(hash('sha256', $targetId), 0, 2)) % 4 !== 0) {
            $targetId .= 'x';
        }
        $state = $simulator->start(12, 2, [
            ['id' => 'hacker', 'name' => 'Hacker', 'policy' => 'scripteur', 'hacker' => true],
            ['id' => $targetId, 'policy' => 'casual'],
        ]);
        $state['players']['hacker']['army']['soldier'] = 500;
        $state['players']['hacker']['glory'] = 40;
        $state['players'][$targetId]['army']['spearman'] = 10;
        $state['players'][$targetId]['glory'] = 40;
        $state['players'][$targetId]['autoSurrender'] = true;
        $state['players']['hacker']['spies'][$targetId] = ['tick' => 1, 'armyTotal' => 10, 'gold' => 2000];
        $state = $simulator->advance($state);
        self::assertSame(5, $state['players']['hacker']['hackerVictims'][$targetId]);
        self::assertSame('abandoned', $state['players'][$targetId]['status']);
        self::assertSame(0, $state['pendingPlayers']['entrant-1']['glory']);
        $hackerPoint = array_values(array_filter($state['frames'][0]['points'],
            static fn (array $point): bool => $point['id'] === 'hacker'))[0];
        self::assertSame('Emmerder l’admin qui regarde la simulation', $hackerPoint['goal']);
        $state = $simulator->advance($state);
        self::assertSame(9, $state['players']['hacker']['hackerVictims'][$targetId]);
        self::assertSame(10, $state['players'][$targetId]['glory']);
        self::assertSame(1, $state['players'][$targetId]['surrenders']);
    }

    public function testSpontaneousEntrantWaitsUntilItsFirstPlayWindow(): void
    {
        $simulator = new EraSimulator(EngineProfile::fromArray(EngineProfile::defaults()), new BagaarFakeRuntime());
        $state = $simulator->start(7, 34, [
            ['id' => 'alice', 'policy' => 'grenouille', 'activity' => 'office'],
            ['id' => 'bob', 'policy' => 'grenouille', 'activity' => 'office'],
        ]);
        $state = $simulator->advance($state, 24);
        self::assertSame(1, $state['spontaneousArrivals']);
        self::assertArrayHasKey('entrant-1', $state['pendingPlayers']);
        self::assertArrayNotHasKey('entrant-1', $state['players']);
        self::assertNotContains('arrival', array_column($state['events'], 'type'));
        $state = $simulator->advance($state, 10);
        self::assertSame(34, $state['players']['entrant-1']['joinedTick']);
        self::assertSame(34, $state['players']['entrant-1']['lastSeenTick']);
        self::assertSame('arrival', array_values(array_filter($state['events'],
            static fn (array $event): bool => $event['actor'] === 'entrant-1'))[0]['type']);
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
        self::assertCount(2, $state['frames'][0]['points']);
        self::assertContains('office', array_column(PlayerObservation::fromState($state, 'always', $profile->costs())['targets'], 'id'));
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

    public function testSignalsRankTheCohortByGloryAndReachThePolicyObservation(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->advance($simulator->start(9, 2, [
            ['id' => 'alpha', 'name' => 'Alpha', 'policy' => 'grenouille'],
            ['id' => 'beta', 'name' => 'Beta', 'policy' => 'grenouille'],
        ]));
        $state['players']['alpha']['status'] = 'abandoned';
        $state['players']['alpha']['glory'] = 500;
        $state['players']['beta']['glory'] = 40;
        $state = $simulator->advance($state);

        $points = array_column($state['frames'][1]['points'], null, 'id');
        self::assertSame('bagaar-signals/1', $points['beta']['signals']['signalsVersion']);
        self::assertSame(2, $points['beta']['signals']['gloryRank']);
        self::assertSame(1, $points['alpha']['signals']['gloryRank']);
        self::assertSame(1, $points['beta']['signals']['activeGloryRank']);
        self::assertNull($points['alpha']['signals']['activeGloryRank']);
        self::assertArrayHasKey('defined', $points['beta']['signals']);

        $view = PlayerObservation::fromState($state, 'beta', $profile->costs());
        self::assertSame(2, $view['self']['signals']['gloryRank']);
        self::assertSame(1, $view['self']['signals']['activeGloryRank']);
        self::assertSame($view['self']['signals']['gloryRank'], array_column($view['ranking'], 'rank', 'id')['beta']);
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

    public function testManualSurrenderIsRecordedAsItsOwnAction(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(3, 1, [
            ['id' => 'frog', 'policy' => 'grenouille'], ['id' => 'farm', 'policy' => 'fermier'],
        ]);
        $state['players']['farm']['glory'] = 40;
        $state['players']['farm']['defenseLossStreak'] = 9;
        $state['players']['farm']['villageCautious'] = true;
        $state = $simulator->advance($state);
        self::assertLessThan(40, $state['players']['farm']['glory']);
        self::assertSame(1, $state['players']['farm']['surrenders']);
        self::assertContains('surrender', array_column($state['events'], 'type'));
    }

    public function testProtestCasualCreatesTwoRealRecruitmentEventsWhenConnected(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $simulator = new EraSimulator($profile, new BagaarFakeRuntime());
        $state = $simulator->start(3, 100, [
            ['id' => 'zoe', 'name' => 'Zoé', 'policy' => 'casual', 'activity' => 'casual-morning', 'protester' => true],
            ['id' => 'frog', 'policy' => 'grenouille'],
        ]);
        $state = $simulator->advance($state, 8);
        $recruits = array_values(array_filter($state['events'], static fn (array $event): bool =>
            $event['type'] === 'recruit' && $event['actor'] === 'zoe'));
        self::assertCount(2, $recruits);
        self::assertSame(['soldier' => 1], $recruits[0]['units']);
        self::assertSame(['soldier' => 1], $recruits[1]['units']);
        self::assertSame(2, $state['players']['zoe']['army']['soldier']);
        $point = array_values(array_filter($state['frames'][7]['points'],
            static fn (array $candidate): bool => $candidate['id'] === 'zoe'))[0];
        self::assertSame('COUCOU JE SUIS UNE BALISE', $point['goal']);
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
