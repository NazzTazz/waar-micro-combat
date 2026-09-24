<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\BudgetCompositionBuilder;

require_once dirname(__DIR__).'/autoload.php';
final class BudgetCompositionBuilderTest extends TestCase
{
    public function testNativeBudgetsAndRequestedSupportsArePreserved(): void
    {
        foreach ([BudgetCompositionBuilder::COSTS, BudgetCompositionBuilder::LEGACY_COSTS] as $costs) {
            $builder = new BudgetCompositionBuilder($costs);
            $armies = $builder->armies();
            self::assertCount(15, $armies);
            self::assertSame($armies, $builder->armies());
            foreach ($armies as $army) {
                $spent = 0;
                foreach ($costs as $unit => $cost) {
                    self::assertIsInt($army['counts'][$unit]);
                    self::assertGreaterThanOrEqual(0, $army['counts'][$unit]);
                    $spent += $army['counts'][$unit] * $cost;
                    if ($army['requestedCostPercent'][$unit] === 0) {
                        self::assertSame(0, $army['counts'][$unit]);
                    }
                }
                self::assertSame($army['budget'], $spent);
                self::assertEqualsWithDelta(100, array_sum($army['actualCostPercent']), 1e-10);
            }
        }
    }
    public function testCostSharesDoNotBecomeHeadcountShares(): void
    {
        $legacy = new BudgetCompositionBuilder(BudgetCompositionBuilder::LEGACY_COSTS);
        $micro = new BudgetCompositionBuilder();
        self::assertSame(2000, $legacy->allocate(20000, [100, 0, 0, 0])['soldier']);
        self::assertSame(250, $micro->allocate(20000, [100, 0, 0, 0])['soldier']);
        self::assertSame(['soldier' => 1398, 'spearman' => 86, 'archer' => 0, 'knight' => 0], $legacy->allocate(20000, [70, 30, 0, 0]));
        self::assertSame(['soldier' => 173, 'spearman' => 56, 'archer' => 0, 'knight' => 0], $micro->allocate(20000, [70, 30, 0, 0]));
        // Symmetric-cost allocations resolve equivalent minima in stable count order.
        self::assertSame(['soldier' => 0, 'spearman' => 107, 'archer' => 108, 'knight' => 9], $legacy->allocate(20000, [0, 40, 40, 20]));
    }
    public function testImpossibleExactBudgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BudgetCompositionBuilder())->allocate(20001, [100, 0, 0, 0]);
    }

    public function testAssociativeWeightsAreRejectedBeforeAllocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BudgetCompositionBuilder())->allocate(20000, ['soldier' => 100, 'spearman' => 0, 'archer' => 0, 'knight' => 0]);
    }

    public function testNonNumericWeightIsRejectedExplicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BudgetCompositionBuilder())->allocate(20000, [[], 0, 0, 100]);
    }
}
