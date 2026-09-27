<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Bagaar\StreamingCohortRuntime;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\ProcessCohortRuntime;

require_once dirname(__DIR__).'/autoload.php';

final class BagaarStreamingRuntimeTest extends TestCase
{
    public function testConsecutiveCombatsMatchTheEstablishedProcessBoundary(): void
    {
        $profile = EngineProfile::fromArray(EngineProfile::defaults());
        $factory = new CohortRequestFactory();
        $persistent = new StreamingCohortRuntime();
        $single = new ProcessCohortRuntime();
        try {
            foreach ([11, 12] as $seed) {
                $request = $factory->combat($profile, ['soldier' => 20], ['spearman' => 10],
                    $seed, 'neutral', 'neutral', [], [], 'none', 'A', 'B');
                self::assertSame($single->resolve($request), $persistent->resolve($request));
            }
        } finally {
            $persistent->close();
        }
    }
}
