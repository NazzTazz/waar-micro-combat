<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\BagaarService;
use Waar\MicroCombat\Bagaar\RunStore;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarServiceTest extends TestCase
{
    public function testDefaultRosterHasFourNamedPlayersPerPolicyWithDifferentSchedules(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'waar-bagaar-roster-'.bin2hex(random_bytes(6));
        $service = new BagaarService(new RunStore($directory));
        try {
            $started = $service->start(['profile' => EngineProfile::defaults(), 'totalTicks' => 1, 'soldierFrog' => true]);
            self::assertCount(24, $started['accounts']);
            foreach (['rageux', 'grenouille', 'ascenseur', 'fermier', 'scripteur', 'casual'] as $policy) {
                self::assertSame(4, count(array_filter($started['accounts'], static fn (array $account): bool => $account['policy'] === $policy)));
            }
            self::assertContains('office', array_column($started['accounts'], 'activity'));
            self::assertContains('all-day', array_column($started['accounts'], 'activity'));
            self::assertContains('casual-morning', array_column($started['accounts'], 'activity'));
            self::assertContains('Chloé', array_column($started['accounts'], 'name'));
            self::assertContains('Hacker', array_column($started['accounts'], 'name'));
            self::assertSame(1, count(array_filter($started['accounts'],
                static fn (array $account): bool => $account['name'] === 'Hacker' && $account['policy'] === 'scripteur')));
            self::assertGreaterThan(1, count(array_unique(array_column($started['accounts'], 'aggressionPercent'))));
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testResumePagesFramesAndEventsByFrameOffset(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'waar-bagaar-pages-'.bin2hex(random_bytes(6));
        $store = new RunStore($directory);
        $service = new BagaarService($store);
        try {
            $frames = [];
            $events = [];
            for ($tick = 1; $tick <= 121; $tick++) {
                $events[] = ['tick' => $tick, 'type' => 'first'];
                $events[] = ['tick' => $tick, 'type' => 'second'];
                $frames[] = ['tick' => $tick, 'eventCount' => count($events), 'points' => []];
            }
            $id = $store->create(['profile' => [], 'state' => [
                'tick' => 121, 'totalTicks' => 121, 'manifest' => [], 'frames' => $frames,
                'events' => $events, 'combats' => [], 'combatCount' => 0, 'archiveDetached' => true]]);
            $first = $service->resume(['runId' => $id, 'frameOffset' => 0]);
            $second = $service->resume(['runId' => $id, 'frameOffset' => $first['nextFrameOffset']]);
            $last = $service->resume(['runId' => $id, 'frameOffset' => $second['nextFrameOffset']]);
            self::assertSame([50, 50, 21], [count($first['frames']), count($second['frames']), count($last['frames'])]);
            self::assertSame([100, 100, 42], [count($first['events']), count($second['events']), count($last['events'])]);
            self::assertSame(121, $last['nextFrameOffset']);
            self::assertFalse($last['hasMoreFrames']);
            self::assertSame($frames, [...$first['frames'], ...$second['frames'], ...$last['frames']]);
            self::assertSame($events, [...$first['events'], ...$second['events'], ...$last['events']]);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRunCanAdvanceAndResumeWithoutExposingStateMutationToClient(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'waar-bagaar-test-'.bin2hex(random_bytes(6));
        $service = new BagaarService(new RunStore($directory));
        try {
            $started = $service->start(['profile' => EngineProfile::defaults(), 'seed' => 4,
                'totalTicks' => 2, 'accounts' => [
                    ['id' => 'frog', 'policy' => 'grenouille'],
                    ['id' => 'script', 'policy' => 'scripteur'],
                ]]);
            self::assertSame(0, $started['tick']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $started['runId']);
            $first = $service->advance(['runId' => $started['runId'], 'steps' => 1]);
            self::assertSame(1, $first['tick']);
            self::assertCount(1, $first['frames']);
            self::assertFalse($first['done']);
            $resumed = $service->resume(['runId' => $started['runId']]);
            self::assertSame($first['frames'], $resumed['frames']);
            $final = $service->advance(['runId' => $started['runId'], 'steps' => 1]);
            self::assertTrue($final['done']);
            self::assertCount(1, $final['frames']);
            self::assertCount(2, $service->resume(['runId' => $started['runId']])['frames']);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
