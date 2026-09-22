<?php

namespace App\Game\Random;

/** Stable event addresses and a nested binomial partition; specification §8.7. */
final readonly class AddressedRandom
{
    public const VERSION = 'sha256-binomial-tree/1';
    private const GRID = 4503599627370496;

    public function __construct(private int $seed, private string $army, private int $round)
    {
        if ($seed < 0 || $seed > 2147483647 || !in_array($army, ['A', 'B'], true) || $round < 0) {
            throw new \InvalidArgumentException('Invalid random event identity.');
        }
    }

    public function domain(string $type, string $usage): string
    {
        return implode("\0", [self::VERSION, (string)$this->seed, $this->army, (string)$this->round, $type, $usage]);
    }

    public function binomial(int $n, float $p, string $type, string $usage): int
    {
        if ($n < 0 || $n > 4294967295 || !is_finite($p) || $p < 0 || $p > 1) {
            throw new \InvalidArgumentException('Invalid addressed binomial parameters.');
        }
        $boundary = (int)floor($p * self::GRID);
        $width = self::GRID;
        $sum = 0;
        $path = '';
        $domain = $this->domain($type, $usage);
        while ($n > 0 && $boundary > 0) {
            if ($boundary === $width) return $sum + $n;
            $left = ConsequenceSampler::fromDomain($domain."\0tree/".$path)->binomial($n, 50);
            $half = intdiv($width, 2);
            if ($boundary <= $half) {
                $n = $left;
                $path .= '0';
            } else {
                $sum += $left;
                $n -= $left;
                $boundary -= $half;
                $path .= '1';
            }
            $width = $half;
        }
        return $sum;
    }

    /** Inclusive, unbiased discrete uniform; rejection stays within this event. */
    public function integer(int $lower, int $upper, string $type, string $usage): int
    {
        if ($lower < 0 || $upper < $lower || $upper > 1000000) throw new \InvalidArgumentException('Invalid accuracy bounds.');
        if ($lower === $upper) return $lower;
        $range = $upper - $lower + 1;
        $bucket = intdiv(self::GRID, $range);
        $limit = $bucket * $range;
        $stream = ConsequenceSampler::fromDomain($this->domain($type, $usage)."\0uniform");
        do { $bits = (int)floor($stream->uniform() * self::GRID); } while ($bits >= $limit);
        return $lower + intdiv($bits, $bucket);
    }
}
