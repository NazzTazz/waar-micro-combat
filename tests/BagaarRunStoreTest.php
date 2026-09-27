<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\RunStore;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarRunStoreTest extends TestCase
{
    public function testDetachedCombatsStayReadableAndExportKeepsTheOriginalShape(): void
    {
        $directory = sys_get_temp_dir().'/waar-bagaar-store-test-'.bin2hex(random_bytes(5));
        $store = new RunStore($directory);
        try {
            $id = $store->create(['profile' => ['label' => 'test'], 'state' => [
                'tick' => 0, 'combatCount' => 0, 'archiveDetached' => true, 'combats' => [], 'frames' => []]]);
            $store->update($id, static function (array $document): array {
                $document['state']['tick'] = 1;
                $document['state']['combatCount'] = 2;
                $document['state']['combats'] = [['event' => ['winner' => 'attacker']], ['event' => ['winner' => null]]];
                return $document;
            });
            self::assertSame([], $store->read($id)['state']['combats']);
            self::assertSame(2, $store->read($id)['state']['combatCount']);
            self::assertSame(['event' => ['winner' => null]], $store->readCombat($id, 1));
            ob_start();
            $store->outputExport($id);
            $export = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['event' => ['winner' => 'attacker']], ['event' => ['winner' => null]]], $export['state']['combats']);
            self::assertSame('test', $export['profile']['label']);
        } finally {
            self::clean($directory);
        }
    }

    public function testLegacyInlineRunMigratesWhenAdvanced(): void
    {
        $directory = sys_get_temp_dir().'/waar-bagaar-store-test-'.bin2hex(random_bytes(5));
        $store = new RunStore($directory);
        try {
            $id = $store->create(['profile' => [], 'state' => ['tick' => 1,
                'combats' => [['event' => ['winner' => 'attacker']]], 'frames' => []]]);
            $store->update($id, static fn (array $document): array => $document);
            $state = $store->read($id)['state'];
            self::assertTrue($state['archiveDetached']);
            self::assertSame(1, $state['combatCount']);
            self::assertSame([], $state['combats']);
            self::assertSame(['event' => ['winner' => 'attacker']], $store->readCombat($id, 0));
        } finally {
            self::clean($directory);
        }
    }

    private static function clean(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
