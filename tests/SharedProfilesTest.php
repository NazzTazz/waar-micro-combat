<?php

namespace Waar\MicroCombat\Workshop {
    // Inject filesystem replacement failures without changing the production API.
    function rename(string $from, string $to): bool
    {
        $hook = $GLOBALS['waarProfileRenameTestHook'] ?? null;
        return $hook !== null ? $hook($from, $to) : \rename($from, $to);
    }
}

namespace Waar\MicroCombat\Tests {
    use PHPUnit\Framework\TestCase;
    use Waar\MicroCombat\Workshop\{EngineProfile, SharedProfiles};

    final class SharedProfilesTest extends TestCase
    {
        private string $directory;

        protected function setUp(): void
        {
            $this->directory = sys_get_temp_dir().'/waar-profile-test-'.bin2hex(random_bytes(8));
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['waarProfileRenameTestHook']);
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->directory)) {
                rmdir($this->directory);
            }
        }

        public function testFailedReplacementPreservesAllProfilesAndAllowsRetry(): void
        {
            $store = new SharedProfiles($this->directory);
            $profile = EngineProfile::defaults();
            $first = $store->save('First', $profile);
            $second = $store->save('Second', $profile);
            $before = file_get_contents($this->directory.'/profiles.json');
            $GLOBALS['waarProfileRenameTestHook'] = function () use ($store, $first, $second): bool {
                self::assertCount(2, $store->listing()['profiles']);
                self::assertSame($first, $store->load($first['id']));
                self::assertSame($second, $store->load($second['id']));
                return false;
            };
            try {
                $store->save('Third', $profile);
                self::fail('A failed replacement must be reported.');
            } catch (\RuntimeException $error) {
                self::assertSame(503, $error->getCode());
            }
            self::assertSame($before, file_get_contents($this->directory.'/profiles.json'));
            self::assertSame([], glob($this->directory.'/profiles-*'));
            unset($GLOBALS['waarProfileRenameTestHook']);
            $third = $store->save('Third', $profile);
            $reopened = new SharedProfiles($this->directory);
            self::assertCount(3, $reopened->listing()['profiles']);
            foreach ([$first, $second, $third] as $row) {
                self::assertSame($row, $reopened->load($row['id']));
            }
        }

        public function testFailedFirstSaveLeavesNoPartialStore(): void
        {
            $store = new SharedProfiles($this->directory);
            $GLOBALS['waarProfileRenameTestHook'] = static fn (): bool => false;
            try {
                $store->save('First', EngineProfile::defaults());
                self::fail('A failed first write must be reported.');
            } catch (\RuntimeException $error) {
                self::assertSame(503, $error->getCode());
            }
            self::assertFileDoesNotExist($this->directory.'/profiles.json');
            self::assertSame([], $store->listing()['profiles']);
            self::assertSame([], glob($this->directory.'/profiles-*'));
        }

        public function testLoadingAPreThresholdProfileNormalizesWithoutRewritingTheStore(): void
        {
            mkdir($this->directory, 0700, true);
            $profile = EngineProfile::defaults();
            unset($profile['combat']['woundDamageThreshold']);
            $row = ['id' => 'legacy', 'name' => 'Legacy', 'createdAt' => '2026-09-20T00:00:00Z', 'profile' => $profile];
            $document = json_encode(['legacy' => $row], JSON_THROW_ON_ERROR);
            file_put_contents($this->directory.'/profiles.json', $document);
            $loaded = (new SharedProfiles($this->directory))->load('legacy');
            self::assertSame('0', $loaded['profile']['combat']['woundDamageThreshold']);
            self::assertSame($document, file_get_contents($this->directory.'/profiles.json'));
        }
    }
}
