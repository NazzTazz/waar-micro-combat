<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\AccountRules;
use Waar\MicroCombat\Bagaar\BuiltinPolicy;
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
        $state = ['tick' => 1, 'totalTicks' => 100, 'manifest' => ['spyRange' => 30],
            'players' => ['script' => $self, 'frog' => $other], 'villages' => [],
            'events' => [['tick' => 1, 'attacker' => 'frog', 'defender' => 'script', 'winner' => 'attacker', 'secretArmy' => $other['army']]]];
        $view = PlayerObservation::fromState($state, 'script', self::COSTS);
        self::assertSame([['id' => 'frog', 'glory' => 0, 'kind' => 'player']], $view['targets']);
        self::assertArrayNotHasKey('secretArmy', $view['events'][0]);
        self::assertSame([], $view['reports']);
        self::assertArrayNotHasKey('army', $view['targets'][0]);
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
        self::assertSame(['type' => 'attack', 'target' => 'enemy'], (new BuiltinPolicy('fermier'))->next($view));
        $view['targets'][] = ['id' => 'village-20', 'glory' => 20, 'kind' => 'village'];
        $view['reports']['village-20'] = ['tick' => 1, 'armyTotal' => 1];
        self::assertSame(['type' => 'attack', 'target' => 'village-20'], (new BuiltinPolicy('fermier'))->next($view));
    }

    public function testVillageFarmersSpyThenRejectAnOversizedGarrison(): void
    {
        foreach (['fermier', 'grenouille', 'ascenseur'] as $name) {
            $view = self::view($name);
            $view['self']['glory'] = 20;
            $view['self']['gold'] = 100;
            $view['self']['autoSurrender'] = true;
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
        $view['self']['gold'] = 100;
        $view['self']['villageFailures']['village-20'] = 8000;
        $view['targets'] = [['id' => 'village-20', 'glory' => 20, 'kind' => 'village']];
        $policy = new BuiltinPolicy('fermier');
        self::assertNull($policy->next($view));
        $view['self']['army']['soldier'] = 125;
        self::assertSame(['type' => 'spy', 'target' => 'village-20'], $policy->next($view));
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

    public function testAscenseurActivatesAutomaticSurrender(): void
    {
        $view = self::view('ascenseur');
        $view['self']['autoSurrender'] = false;
        self::assertSame(['type' => 'autoSurrender', 'enabled' => true], (new BuiltinPolicy('ascenseur'))->next($view));
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
