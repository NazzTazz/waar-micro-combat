<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\AccountRules;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarAccountRulesTest extends TestCase
{
    private const COSTS = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];

    public function testInitialAccountAndMineProgressionUseHostRules(): void
    {
        $account = AccountRules::initial('rageux', 'rageux');
        self::assertSame(2000, $account['gold']);
        self::assertSame(0, $account['mineLevel']);
        $account = AccountRules::buyMine($account);
        self::assertSame(1, $account['mineLevel']);
        self::assertSame(1992, $account['gold']);
        $account = AccountRules::hourly($account, 40);
        self::assertSame(2000, $account['gold']);
        self::assertSame(9, $account['attacks']);
    }

    public function testResetKeepsTheSameAccountIdentityButStartsItClean(): void
    {
        $account = AccountRules::initial('same-id', 'scripteur');
        $account['name'] = 'Alice';
        $account['originName'] = 'Alice';
        $account['activity'] = 'office';
        $account['aggressionPercent'] = 95;
        $account['gold'] = 18000;
        $account['glory'] = 47;
        $account['mineLevel'] = 9;
        $account['army']['archer'] = 20;
        $account['record']['losses'] = 17;
        $account['spies']['target'] = ['gold' => 20];
        $account['resetCount'] = 1;
        $fresh = AccountRules::reset($account, 123);
        self::assertSame('same-id', $fresh['id']);
        self::assertSame('Alice', $fresh['name']);
        self::assertSame('scripteur', $fresh['policy']);
        self::assertSame('office', $fresh['activity']);
        self::assertSame(95, $fresh['aggressionPercent']);
        self::assertSame(2, $fresh['resetCount']);
        self::assertSame(123, $fresh['joinedTick']);
        self::assertSame(2000, $fresh['gold']);
        self::assertSame(0, $fresh['glory']);
        self::assertSame(0, $fresh['mineLevel']);
        self::assertSame(0, array_sum($fresh['army']));
        self::assertSame(['wins' => 0, 'draws' => 0, 'losses' => 0], $fresh['record']);
        self::assertSame([], $fresh['spies']);
        self::assertSame('active', $fresh['status']);
    }

    public function testRecruitmentAndHealingUsePresetPrices(): void
    {
        $account = AccountRules::initial('scripteur', 'scripteur');
        $account = AccountRules::buyHospital($account);
        $account = AccountRules::recruit($account, ['soldier' => 2, 'archer' => 1], self::COSTS);
        self::assertSame(710, $account['gold']);
        $account['army']['soldier']--;
        $account['hospital']['soldier']++;
        $healed = AccountRules::heal($account, self::COSTS);
        self::assertSame(2, $healed['army']['soldier']);
        self::assertSame(0, $healed['hospital']['soldier']);
        self::assertSame(638, $healed['gold']);
    }

    public function testSpyOnlyRevealsHostReportAndCostsGold(): void
    {
        $agent = AccountRules::initial('script', 'scripteur');
        $target = AccountRules::initial('target', 'grenouille');
        $target['glory'] = 30;
        $target['army']['knight'] = 5;
        $target['gold'] = 900;
        $result = AccountRules::spy($agent, $target, 30);
        self::assertSame(['gold' => 900, 'armyTotal' => 5, 'glory' => 30, 'morale' => 'high'], $result['spies']['target']);
        self::assertArrayNotHasKey('army', $result['spies']['target']);
        $target['glory'] = 31;
        $this->expectException(\DomainException::class);
        AccountRules::spy($result, $target, 30);
    }

    public function testHourlyAttritionAndPrisonerLossesAreDeterministic(): void
    {
        $account = AccountRules::initial('one', 'ascenseur');
        $account['hospitalLevel'] = 1;
        $account['hospital']['soldier'] = 10;
        $account['prisoners'] = 20;
        $after = AccountRules::hourly($account, 40);
        self::assertSame(0, $after['hospital']['soldier']);
        self::assertSame(19, $after['prisoners']);
        self::assertGreaterThan(2000, $after['gold']);
    }
}
