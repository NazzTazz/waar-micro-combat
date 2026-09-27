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
        $target['glory'] = 6;
        $target['army']['knight'] = 5;
        $target['gold'] = 900;
        $result = AccountRules::spy($agent, $target, 10);
        self::assertSame(['gold' => 900, 'armyTotal' => 5, 'glory' => 6, 'morale' => 'high'], $result['spies']['target']);
        self::assertArrayNotHasKey('army', $result['spies']['target']);
        $target['glory'] = 11;
        $this->expectException(\DomainException::class);
        AccountRules::spy($result, $target, 10);
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
