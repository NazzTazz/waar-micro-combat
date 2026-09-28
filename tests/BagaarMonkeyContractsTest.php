<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\LuaParameters;
use Waar\MicroCombat\Bagaar\PlayerEntrants;
use Waar\MicroCombat\Bagaar\SharedBagaarLibrary;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarMonkeyContractsTest extends TestCase
{
    public function testParameterSchemaKeepsIndividualValuesWithinDeclaredBounds(): void
    {
        $schema = LuaParameters::schema([
            ['name' => 'OBSTINATION', 'type' => 'slider', 'min' => 0, 'max' => 100,
                'step' => 5, 'default' => 50, 'description' => 'Seuil de repli'],
            ['name' => 'SOIGNER', 'type' => 'toggle', 'default' => true],
        ]);
        self::assertSame(['OBSTINATION' => 20, 'SOIGNER' => true],
            LuaParameters::values($schema, ['OBSTINATION' => 20]));
        self::assertSame(['OBSTINATION' => 95, 'SOIGNER' => false],
            LuaParameters::values($schema, ['OBSTINATION' => 95, 'SOIGNER' => false]));
        $this->expectException(\InvalidArgumentException::class);
        LuaParameters::values($schema, ['OBSTINATION' => 97]);
    }

    public function testEntrantCopiesConfigurationButStartsWithFreshEconomy(): void
    {
        $entrant = PlayerEntrants::create(5, 48, ['policy' => 'lua', 'scriptKey' => 's123',
            'activity' => 'office', 'aggressionPercent' => 120,
            'parameters' => ['OBSTINATION' => 95]]);
        self::assertSame('lua', $entrant['policy']);
        self::assertSame('s123', $entrant['scriptKey']);
        self::assertSame(['OBSTINATION' => 95], $entrant['parameters']);
        self::assertSame('office', $entrant['activity']);
        self::assertSame(2000, $entrant['gold']);
        self::assertSame(0, array_sum($entrant['army']));
    }

    public function testSharedPopulationIsImmutableAndListedWithoutItsAccounts(): void
    {
        $directory = sys_get_temp_dir().'/waar-bagaar-library-test-'.bin2hex(random_bytes(8));
        try {
            $store = new SharedBagaarLibrary($directory);
            $accounts = [['id' => 'one', 'policy' => 'rageux'], ['id' => 'two', 'policy' => 'casual']];
            $saved = $store->savePopulation('Essai 1', $accounts, [['policy' => 'fermier', 'weight' => .25]]);
            self::assertSame($accounts, $store->loadPopulation($saved['id'])['accounts']);
            self::assertArrayNotHasKey('accounts', $store->listing()['populations'][0]);
            try {
                $store->savePopulation('Essai 1', $accounts, []);
                self::fail('A published population revision must be immutable.');
            } catch (\RuntimeException $error) {
                self::assertSame(409, $error->getCode());
            }
        } finally {
            foreach (glob($directory.'/library/populations/*.json') ?: [] as $path) unlink($path);
            if (is_file($directory.'/library/library.lock')) unlink($directory.'/library/library.lock');
            if (is_dir($directory.'/library/populations')) rmdir($directory.'/library/populations');
            if (is_dir($directory.'/library')) rmdir($directory.'/library');
            if (is_dir($directory)) rmdir($directory);
        }
    }
}
