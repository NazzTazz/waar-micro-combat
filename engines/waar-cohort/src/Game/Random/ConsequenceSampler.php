<?php

namespace App\Game\Random;

/** Dedicated binomial stream. Protocol and numerical method: specification §9.5. */
final class ConsequenceSampler
{
    public const VERSION = 'sha256-counter52-binomial-btrs/1';
    private int $counter = 0;
    private string $domain;

    public function __construct(int $seed, string $side, string $type, string $stage)
    {
        $this->domain = implode("\0", [self::VERSION, 'wounded-capture-then-compress/3', (string)$seed, $side, $type, $stage]);
    }

    /** Open interval (0,1), 52 random bits, no zero/log(0) or endpoint retry. */
    public function uniform(): float
    {
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', $this->domain."\0".$this->counter++, true), 0, 8));
        return ($words['high'] * 1048576 + ($words['low'] >> 12) + 0.5) / 4503599627370496;
    }

    public function binomial(int $n, int $percent): int
    {
        if ($n < 0 || $n > 4294967295 || $percent < 0 || $percent > 100) throw new \InvalidArgumentException('Invalid consequence binomial parameters.');
        if ($n === 0 || $percent === 0) return 0;
        if ($percent === 100) return $n;
        // Compute the smaller probability from integer percentages in both runtimes.
        $p = min($percent, 100 - $percent) / 100;
        if ($n * $p < 30) {
            // Invert geometric waiting times; expected work <= 31 uniforms, even for u32 n.
            $limit = log1p(-$p); $position = 0.0; $sample = 0;
            while (true) {
                $position += floor(log($this->uniform()) / $limit) + 1;
                if ($position > $n) break;
                ++$sample;
            }
        } else {
            $sample = $this->btrs($n, $p);
        }
        return $percent > 50 ? $n - $sample : $sample;
    }

    /** Hörmann's transformed rejection, with the logarithmic acceptance test. */
    private function btrs(int $n, float $p): int
    {
        $sigma = sqrt($n * $p * (1 - $p));
        $b = 1.15 + 2.53 * $sigma;
        $a = -0.0873 + 0.0248 * $b + 0.01 * $p;
        $center = $n * $p + 0.5;
        $squeeze = 0.92 - 4.2 / $b;
        $alpha = (2.83 + 5.1 / $b) * $sigma;
        $mode = floor(($n + 1) * $p);
        $odds = $p / (1 - $p);
        while (true) {
            $u = $this->uniform() - 0.5; $v = $this->uniform();
            $us = 0.5 - abs($u);
            $k = floor((2 * $a / $us + $b) * $u + $center);
            if ($k < 0 || $k > $n) continue;
            if ($us >= 0.07 && $v <= $squeeze) return (int)$k;
            // log(P(X=k)/P(X=mode)); log1p avoids near-one cancellation at large n.
            $bound = ($mode + 0.5) * log(($mode + 1) / ($odds * ($n - $mode + 1)))
                + ($n + 1) * log1p(($k - $mode) / ($n - $k + 1))
                + ($k + 0.5) * log($odds * ($n - $k + 1) / ($k + 1))
                + self::tail($mode) + self::tail($n - $mode) - self::tail($k) - self::tail($n - $k);
            if (log($v * $alpha / ($a / ($us * $us) + $b)) <= $bound) return (int)$k;
        }
    }

    /** log(k!) - ((k+.5)log(k+1)-(k+1)+log(2π)/2). */
    private static function tail(float $k): float
    {
        $small = [0.08106146679532726, 0.04134069595540929, 0.02767792568499834, 0.02079067210376509,
            0.01664469118982119, 0.01387612882307075, 0.01189670994589177, 0.01041126526197210,
            0.009255462182712733, 0.008330563433362871];
        if ($k < 10) return $small[(int)$k];
        $x = $k + 1; $square = $x * $x;
        return (1 / 12 - (1 / 360 - (1 / 1260 - (1 / 1680 - 1 / 1188 / $square) / $square) / $square) / $square) / $x;
    }
}
