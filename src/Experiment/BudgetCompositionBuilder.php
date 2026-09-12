<?php

namespace Waar\MicroCombat\Experiment;

/** Integer army allocation; never evaluates combat or chooses a ruleset. */
final class BudgetCompositionBuilder
{
    public const COSTS = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];
    public const LEGACY_COSTS = ['soldier' => 10, 'spearman' => 70, 'archer' => 70, 'knight' => 550];
    public const MIXES = [[100, 0, 0, 0], [70, 30, 0, 0], [70, 0, 30, 0], [70, 0, 0, 30], [0, 40, 40, 20]];

    public function __construct(private readonly array $costs = self::COSTS)
    {
        if (array_keys($costs) !== array_keys(self::COSTS) || array_filter($costs, static fn($cost) => !is_int($cost) || $cost <= 0)) {
            throw new \InvalidArgumentException('Expected four positive integer unit costs.');
        }
    }

    public function armies(): array
    {
        $armies = [];
        foreach ([20000, 50000, 80000] as $budget) {
            foreach (self::MIXES as $index => $weights) {
                $counts = $this->allocate($budget, $weights);
                $shares = [];
                foreach ($this->costs as $unit => $cost) {
                    $shares[$unit] = 100 * $counts[$unit] * $cost / $budget;
                }
                $armies[] = ['id' => 'b'.$budget.'-c'.($index + 1), 'budget' => $budget, 'composition' => $index + 1, 'requestedCostPercent' => array_combine(array_keys(self::COSTS), $weights), 'actualCostPercent' => $shares, 'counts' => $counts, 'label' => ($budget / 1000).'k · '.implode('/', $weights)];
            }
        }
        return $armies;
    }

    public function allocate(int $budget, array $weights): array
    {
        if ($budget < 1 || $budget > 80000 || !array_is_list($weights) || count($weights) !== 4 || array_filter($weights, static fn($w) => !is_int($w) || $w < 0) || array_sum($weights) !== 100) {
            throw new \InvalidArgumentException('Expected a budget <=80000 and four non-negative integer percentages summing to 100.');
        }
        $units = array_keys($this->costs);
        $active = array_values(array_filter(range(0, 3), static fn(int $i): bool => $weights[$i] > 0));
        // User corpus has at most three active types; bound exhaustive allocation.
        if (count($active) > 3) {
            throw new \InvalidArgumentException('At most three active unit types are supported.');
        }
        $best = null;
        $bestError = PHP_INT_MAX;
        $visit = function (int $position, int $remaining, array $counts, int $error) use (&$visit, &$best, &$bestError, $active, $units, $weights, $budget): void {
            $i = $active[$position];
            $unit = $units[$i];
            $cost = $this->costs[$unit];
            if ($position === count($active) - 1) {
                if ($remaining % $cost !== 0) return;
                $counts[$unit] = intdiv($remaining, $cost);
                $error += (100 * $remaining - $budget * $weights[$i]) ** 2;
                // Ascending lexicographic count order is the deterministic tie-break.
                if ($error < $bestError) { $best = $counts; $bestError = $error; }
                return;
            }
            for ($count = 0; $count <= intdiv($remaining, $cost); ++$count) {
                $nextError = $error + (100 * $count * $cost - $budget * $weights[$i]) ** 2;
                if ($nextError > $bestError) continue;
                $counts[$unit] = $count;
                $visit($position + 1, $remaining - $count * $cost, $counts, $nextError);
            }
        };
        $visit(0, $budget, array_fill_keys($units, 0), 0);
        return $best ?? throw new \InvalidArgumentException('No exact integer allocation for this budget and support.');
    }
}
