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

    public function testDetachedTracePagesAndExportPreserveAllFramesAndEvents(): void
    {
        $directory = sys_get_temp_dir().'/waar-bagaar-trace-test-'.bin2hex(random_bytes(5));
        $store = new RunStore($directory);
        try {
            $id = $store->create(['profile' => [], 'state' => ['tick' => 0,
                'archiveDetached' => true, 'traceDetached' => true, 'combatCount' => 0,
                'frameCount' => 0, 'eventCount' => 0, 'combats' => [], 'frames' => [], 'events' => []]]);
            $first = $store->update($id, static function (array $document): array {
                $document['state']['tick'] = 2;
                $document['state']['frames'] = [['tick' => 1, 'eventCount' => 1], ['tick' => 2, 'eventCount' => 3]];
                $document['state']['events'] = [['tick' => 1], ['tick' => 2, 'type' => 'combat'], ['tick' => 2]];
                return $document;
            });
            self::assertCount(2, $first['state']['frames']);
            $store->update($id, static function (array $document): array {
                $document['state']['tick'] = 3;
                $document['state']['frames'] = [['tick' => 3, 'eventCount' => 4]];
                $document['state']['events'] = [['tick' => 3]];
                return $document;
            });
            $stored = $store->read($id)['state'];
            self::assertSame([3, 4], [$stored['frameCount'], $stored['eventCount']]);
            self::assertSame([], $stored['frames']);
            self::assertSame([], $stored['events']);
            self::assertSame([['tick' => 2, 'eventCount' => 3], ['tick' => 3, 'eventCount' => 4]],
                $store->readTrace($id, 'frames', 1, 2));
            self::assertSame([['tick' => 2, 'type' => 'combat'], ['tick' => 2]],
                $store->readTrace($id, 'events', 1, 2));
            ob_start();
            $store->outputExport($id);
            $export = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(3, $export['state']['frames']);
            self::assertCount(4, $export['state']['events']);
        } finally {
            self::clean($directory);
        }
    }

    public function testLegacyTraceMigrationRetainsPlayerObservation(): void
    {
        $directory = sys_get_temp_dir().'/waar-bagaar-trace-test-'.bin2hex(random_bytes(5));
        $store = new RunStore($directory);
        try {
            $id = $store->create(['profile' => [], 'state' => ['tick' => 1,
                'archiveDetached' => true, 'combatCount' => 0, 'combats' => [],
                'players' => ['a' => [], 'b' => []],
                'frames' => [['tick' => 1, 'eventCount' => 1]],
                'events' => [['tick' => 1, 'type' => 'combat', 'attacker' => 'a', 'defender' => 'b', 'winner' => 'attacker']]]]);
            self::assertSame(['frames' => 1, 'events' => 1], $store->migrateTrace($id));
            $state = $store->read($id)['state'];
            self::assertSame([], $state['frames']);
            self::assertSame([], $state['events']);
            self::assertSame('attacker', $state['observationEvents']['b'][0]['winner']);
            self::assertSame([['tick' => 1, 'eventCount' => 1]], $store->readTrace($id, 'frames', 0, 1));
            self::assertSame(['frames' => 1, 'events' => 1], $store->migrateTrace($id));
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
