<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\BagaarService;
use Waar\MicroCombat\Bagaar\RunStore;
use Waar\MicroCombat\Workshop\EngineProfile;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarServiceTest extends TestCase
{
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
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
