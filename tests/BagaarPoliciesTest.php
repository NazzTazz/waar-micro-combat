<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\AccountRules;
use Waar\MicroCombat\Bagaar\BuiltinPolicy;
use Waar\MicroCombat\Bagaar\HostRules;
use Waar\MicroCombat\Bagaar\PlayerObservation;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarPoliciesTest extends TestCase
{
    private const COSTS = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];

    public function testObservationExcludesHiddenOpponentState(): void
    {
        $self = AccountRules::initial('script', 'scripteur');
        $other = AccountRules::initial('frog', 'grenouille');
        $other['army']['knight'] = 20;
        $other['gold'] = 9900;
        $other['status'] = 'abandoned';
        $state = ['tick' => 1, 'totalTicks' => 100, 'manifest' => ['spyRange' => 30],
            'players' => ['script' => $self, 'frog' => $other], 'villages' => [],
            'events' => [['tick' => 1, 'attacker' => 'frog', 'defender' => 'script', 'winner' => 'attacker', 'secretArmy' => $other['army']]]];
        $view = PlayerObservation::fromState($state, 'script', self::COSTS);
        self::assertSame([['id' => 'frog', 'name' => 'frog', 'glory' => 0, 'kind' => 'player']], $view['targets']);
        self::assertArrayNotHasKey('secretArmy', $view['events'][0]);
        self::assertSame([], $view['reports']);
        self::assertArrayNotHasKey('army', $view['targets'][0]);
        self::assertArrayNotHasKey('status', $view['targets'][0]);
    }

    public function testRageuxRetaliatesThreeTimesWithoutProfitabilityCheck(): void
    {
        $view = self::view('rageux');
        $view['events'] = [['tick' => 1, 'attacker' => 'enemy', 'defender' => 'self', 'winner' => 'attacker']];
        $policy = new BuiltinPolicy('rageux');
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(['type' => 'attack', 'target' => 'enemy'], $policy->next($view));
            $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        }
        self::assertNull($policy->next($view));
    }

    public function testAggressionChangesRetaliationCountButVillageQuotaRemainsThree(): void
    {
        $view = self::view('rageux');
        $view['events'] = [['tick' => 1, 'attacker' => 'enemy', 'defender' => 'self', 'winner' => 'attacker']];
        $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        $view['self']['aggressionPercent'] = 80;
        self::assertNull((new BuiltinPolicy('rageux'))->next($view));
        $view['self']['aggressionPercent'] = 120;
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('rageux'))->next($view));
        $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        self::assertNull((new BuiltinPolicy('rageux'))->next($view));

        $farmer = self::view('fermier');
        $farmer['self']['aggressionPercent'] = 120;
        $farmer['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village']];
        $farmer['reports']['village-20'] = ['tick' => 1, 'armyTotal' => 1];
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(['type' => 'attack', 'target' => 'village-20'], (new BuiltinPolicy('fermier'))->next($farmer));
            $farmer['attempts'][] = ['type' => 'attack', 'target' => 'village-20'];
        }
        self::assertNull((new BuiltinPolicy('fermier'))->next($farmer));
    }

    public function testGrenouilleWaitsThenCanAttackPlayers(): void
    {
        $view = self::view('grenouille');
        self::assertNull((new BuiltinPolicy('grenouille'))->next($view));
        $view['tick'] = 75;
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('grenouille'))->next($view));
    }

    public function testFermierSwitchesToVillages(): void
    {
        $view = self::view('fermier');
        $view['self']['fridges'] = ['enemy'];
        $view['tick'] = 6;
        $view['reports']['enemy'] = ['tick' => 6, 'armyTotal' => 1, 'gold' => 1000];
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('fermier'))->next($view));
        $view['tick'] = 7;
        $view['targets'][] = ['id' => 'village-20', 'glory' => 20, 'kind' => 'village'];
        $view['reports']['enemy']['armyTotal'] = 100;
        $view['reports']['village-20'] = ['tick' => 7, 'armyTotal' => 1];
        self::assertSame(['type' => 'attack', 'target' => 'village-20'], (new BuiltinPolicy('fermier'))->next($view));
    }

    public function testFarmerPrioritizesAffordableVillageAndClimbsAfterOutgrowingIt(): void
    {
        $view = self::view('fermier');
        $view['tick'] = 20;
        $view['costs'] = ['soldier' => 10, 'spearman' => 100, 'archer' => 50, 'knight' => 250];
        $view['self']['glory'] = 72;
        $view['self']['army']['soldier'] = 90000;
        $view['targets'] = [
            ['id' => 'village-60', 'glory' => 60, 'kind' => 'village'],
            ['id' => 'enemy', 'glory' => 72, 'kind' => 'player'],
        ];
        $view['reports']['village-60'] = ['tick' => 20, 'glory' => 60, 'armyTotal' => 11906, 'gold' => 25800];
        $view['reports']['enemy'] = ['tick' => 20, 'armyTotal' => 1, 'gold' => 1000];
        $policy = new BuiltinPolicy('fermier');

        self::assertSame(['type' => 'attack', 'target' => 'village-60'], $policy->next($view));
        $view['self']['glory'] = 88;
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], $policy->next($view));
    }

    public function testFarmerSeeksCrownAndUsesEspionageToChooseAnEndgameRival(): void
    {
        $view = self::view('fermier');
        $view['tick'] = 80;
        $view['rwaa'] = 'leader';
        $view['targets'][] = ['id' => 'leader', 'glory' => 15, 'kind' => 'player'];
        $view['reports']['enemy'] = ['tick' => 80, 'armyTotal' => 2, 'gold' => 1000];
        $view['reports']['leader'] = ['tick' => 80, 'armyTotal' => 2, 'gold' => 1000];
        $policy = new BuiltinPolicy('fermier');
        self::assertSame(['type' => 'attack', 'target' => 'leader'], $policy->next($view));
        self::assertSame('Prendre la couronne', $policy->intention($view['self'], 80, 100, [])['goal']);
    }

    public function testRageuxKeepsHisTargetForThreeTicks(): void
    {
        $view = self::view('rageux');
        $view['self']['rageTarget'] = 'enemy';
        $view['self']['rageUntil'] = 12;
        $view['tick'] = 10;
        $policy = new BuiltinPolicy('rageux');
        self::assertSame('Détruire Alice', $policy->intention($view['self'], 10, 100, ['enemy' => 'Alice'])['goal']);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(['type' => 'attack', 'target' => 'enemy'], $policy->next($view));
            $view['attempts'][] = ['type' => 'attack', 'target' => 'enemy'];
        }
        self::assertNull($policy->next($view));
    }

    public function testMineSavingsStillFundsRecruitment(): void
    {
        $view = self::view('fermier');
        $view['self']['mineLevel'] = 5;
        $view['self']['gold'] = 500;
        $view['targets'] = [];
        $view['attempts'] = [['type' => 'mine'], ['type' => 'hospital']];
        $policy = new BuiltinPolicy('fermier');
        self::assertSame('Monter la mine suivante', $policy->intention($view['self'], 10, 100, [])['goal']);
        self::assertSame('recruit', $policy->next($view)['type']);
        $view['self']['glory'] = 0;
        $view['self']['gold'] = 1000;
        self::assertSame('recruit', $policy->next($view)['type']);
    }

    public function testVillageFarmersSpyThenRejectAnOversizedGarrison(): void
    {
        foreach (['fermier', 'grenouille', 'ascenseur'] as $name) {
            $view = self::view($name);
            $view['self']['glory'] = 20;
            $view['self']['gold'] = 100;
            $view['self']['autoSurrender'] = false;
            $view['self']['cyclePhase'] = 'rebuild';
            $view['self']['peakArmyGold'] = 20000;
            $view['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village']];
            $policy = new BuiltinPolicy($name);

            self::assertSame(['type' => 'spy', 'target' => 'village-20'], $policy->next($view), $name);
            $view['attempts'][] = ['type' => 'spy', 'target' => 'village-20'];
            $view['reports']['village-20'] = ['tick' => 1, 'armyTotal' => 100];
            self::assertNull($policy->next($view), $name);
            $view['tick'] = 2;
            self::assertNull($policy->next($view), $name);
            $view['tick'] = 7;
            $view['attempts'] = array_values(array_filter($view['attempts'],
                static fn (array $attempt): bool => ($attempt['target'] ?? null) !== 'village-20'));
            self::assertSame(['type' => 'spy', 'target' => 'village-20'], $policy->next($view), $name);
            $view['tick'] = 1;
            $view['attempts'][] = ['type' => 'spy', 'target' => 'village-20'];
            $view['reports']['village-20']['armyTotal'] = 20;
            self::assertSame(['type' => 'attack', 'target' => 'village-20'], $policy->next($view), $name);
        }
    }

    public function testFarmerWaitsForRecoveryAfterLosingToVillage(): void
    {
        $view = self::view('fermier');
        $view['self']['glory'] = 20;
        $view['self']['gold'] = 1500;
        $view['self']['villageFailures']['village-20'] = 8000;
        $view['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village']];
        $policy = new BuiltinPolicy('fermier');
        self::assertNull($policy->next($view));
        $view['self']['army']['soldier'] = 125;
        self::assertSame(['type' => 'spy', 'target' => 'village-20'], $policy->next($view));
    }

    public function testVillageFailureRequiresOneDayFreshSpyAndFullGold(): void
    {
        $view = self::view('fermier');
        $view['self']['glory'] = 20;
        $view['self']['gold'] = 100;
        $view['self']['villageFailures']['village-20'] = 8000;
        $view['self']['villageCautious'] = true;
        $view['self']['lastVillageAttackTick'] = 1;
        $view['self']['army']['soldier'] = 150;
        $view['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village'],
            ['id' => 'village-40', 'glory' => 40, 'kind' => 'village']];
        $view['reports']['village-20'] = ['tick' => 1, 'armyTotal' => 20, 'gold' => 1];
        $view['reports']['village-40'] = ['tick' => 24, 'armyTotal' => 1,
            'gold' => 24 * HostRules::mineProduction(10)];
        $policy = new BuiltinPolicy('fermier');
        self::assertSame('Agrandir mon armée', $policy->intention($view['self'], 2, 100, [])['goal']);
        $view['tick'] = 24;
        self::assertNull($policy->next($view));
        $view['tick'] = 25;
        self::assertSame(['type' => 'spy', 'target' => 'village-20'], $policy->next($view));
        $view['attempts'][] = ['type' => 'spy', 'target' => 'village-20'];
        $view['reports']['village-20'] = ['tick' => 25, 'armyTotal' => 20, 'gold' => 100];
        self::assertNull($policy->next($view));
        $view['reports']['village-20']['gold'] = 24 * HostRules::mineProduction(9);
        self::assertSame(['type' => 'attack', 'target' => 'village-20'], $policy->next($view));
    }

    public function testVillagesRemainAvailableToScripteurAndCasual(): void
    {
        foreach (['scripteur', 'casual'] as $name) {
            $view = self::view($name);
            $view['self']['glory'] = 20;
            $view['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village']];
            $view['reports']['village-20'] = ['tick' => 1, 'armyTotal' => 1, 'gold' => 1000];
            self::assertSame(['type' => 'attack', 'target' => 'village-20'], (new BuiltinPolicy($name))->next($view), $name);
        }
    }

    public function testFarmerUsesRecentSpyReportBeyondEspionageTick(): void
    {
        $view = self::view('fermier');
        $view['tick'] = 7;
        $view['reports']['enemy'] = ['tick' => 6, 'armyTotal' => 1, 'gold' => 3000];
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('fermier'))->next($view));
    }

    public function testScripteurHealsThenAttacksOnlyOnHisOwnEspionageEstimate(): void
    {
        $view = self::view('scripteur');
        $view['self']['hospital']['soldier'] = 1;
        self::assertSame(['type' => 'heal'], (new BuiltinPolicy('scripteur'))->next($view));
        $view['self']['hospital']['soldier'] = 0;
        self::assertNull((new BuiltinPolicy('scripteur'))->next($view));
        $view['reports']['enemy'] = ['tick' => 1, 'gold' => 10000, 'armyTotal' => 1, 'glory' => 0, 'morale' => 'high'];
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('scripteur'))->next($view));
    }

    public function testScripteurAggressionChangesHisProfitabilityThreshold(): void
    {
        $view = self::view('scripteur');
        $view['reports']['enemy'] = ['tick' => 1, 'gold' => 10000, 'armyTotal' => 36, 'glory' => 0, 'morale' => 'high'];
        $view['self']['aggressionPercent'] = 80;
        self::assertNull((new BuiltinPolicy('scripteur'))->next($view));
        $view['self']['aggressionPercent'] = 120;
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('scripteur'))->next($view));
    }

    public function testScripteurCanFarmAWeakRichAccountEvenWithALargeArmy(): void
    {
        $view = self::view('scripteur');
        $view['self']['army']['soldier'] = 1000;
        $view['reports']['enemy'] = ['tick' => 1, 'gold' => 1000, 'armyTotal' => 2, 'glory' => 0, 'morale' => 'low'];
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('scripteur'))->next($view));
    }

    public function testHackerPursuesHisDrawingUsingOnlySpyReports(): void
    {
        $view = self::view('scripteur');
        $view['self']['hacker'] = true;
        $view['self']['hackerVictims'] = [];
        $view['self']['army']['soldier'] = 500;
        $view['targets'][] = ['id' => 'near-p', 'glory' => 20, 'kind' => 'player'];
        $view['reports']['enemy'] = ['tick' => 1, 'gold' => 10000, 'armyTotal' => 10];
        $view['reports']['near-p'] = ['tick' => 1, 'gold' => 0, 'armyTotal' => 36];
        $policy = new BuiltinPolicy('scripteur');
        self::assertSame('Emmerder l’admin qui regarde la simulation', $policy->intention($view['self'], 1, 100, [])['goal']);
        self::assertSame(['type' => 'attack', 'target' => 'near-p'], $policy->next($view));
        $view['self']['hackerVictims']['near-p'] = 9;
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], $policy->next($view));
        $view['reports'] = [];
        self::assertNull($policy->next($view));
    }

    public function testScripteurPursuesMineGloryWithVerySafeFightsThenSavesGold(): void
    {
        $view = self::view('scripteur');
        $view['self']['mineLevel'] = 8;
        $view['self']['glory'] = 15;
        $view['reports']['enemy'] = ['tick' => 1, 'gold' => 0, 'armyTotal' => 1];
        $policy = new BuiltinPolicy('scripteur');
        self::assertSame('Monter la mine suivante', $policy->intention($view['self'], 1, 100, [])['goal']);
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], $policy->next($view));

        $view['self']['glory'] = 20;
        $view['self']['gold'] = 100;
        $view['self']['hospitalLevel'] = 0;
        $view['attempts'] = [['type' => 'mine']];
        self::assertNull($policy->next($view));
        self::assertStringContainsString('Suspendre le recrutement', $policy->intention($view['self'], 1, 100, [])['method']);
    }

    public function testAscenseurActivatesAutomaticSurrender(): void
    {
        $view = self::view('ascenseur');
        $view['self']['autoSurrender'] = false;
        $view['self']['cyclePhase'] = 'surrender';
        self::assertSame(['type' => 'autoSurrender', 'enabled' => true], (new BuiltinPolicy('ascenseur'))->next($view));
        $view['self']['cyclePhase'] = 'rebuild';
        $view['self']['autoSurrender'] = true;
        self::assertSame(['type' => 'autoSurrender', 'enabled' => false], (new BuiltinPolicy('ascenseur'))->next($view));
    }

    public function testFarmerCanChooseManualSurrenderToReachLowerVillages(): void
    {
        $view = self::view('fermier');
        $view['self']['glory'] = 40;
        $view['self']['defenseLossStreak'] = 9;
        $view['self']['villageCautious'] = true;
        self::assertSame(['type' => 'surrender'], (new BuiltinPolicy('fermier'))->next($view));
        $view['self']['villageCautious'] = false;
        self::assertNotSame(['type' => 'surrender'], (new BuiltinPolicy('fermier'))->next($view));
    }

    public function testProtestCasualChoosesTwoSeparateSoldierPurchases(): void
    {
        $view = self::view('casual');
        $view['self']['protester'] = true;
        $view['self']['gold'] = 1000;
        $view['attempts'] = [['type' => 'mine']];
        $policy = new BuiltinPolicy('casual');
        self::assertSame('COUCOU JE SUIS UNE BALISE', $policy->intention($view['self'], 1, 100, [])['goal']);
        $action = ['type' => 'recruit', 'units' => ['soldier' => 1]];
        self::assertSame($action, $policy->next($view));
        $view['attempts'][] = $action;
        self::assertSame($action, $policy->next($view));
        $view['attempts'][] = $action;
        self::assertNull($policy->next($view));
    }

    private static function view(string $policy): array
    {
        $self = AccountRules::initial('self', $policy);
        $self['gold'] = 0;
        $self['army']['soldier'] = 100;
        $self['attacks'] = 9;
        return ['tick' => 1, 'totalTicks' => 100, 'spyRange' => 30, 'self' => $self,
            'targets' => [['id' => 'enemy', 'glory' => 0, 'kind' => 'player']],
            'reports' => [], 'events' => [], 'costs' => self::COSTS,
            'attempts' => [['type' => 'mine'], ['type' => 'hospital'], ['type' => 'recruit'], ['type' => 'spy', 'target' => 'enemy']]];
    }
}
