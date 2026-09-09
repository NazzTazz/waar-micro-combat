<?php

namespace Waar\MicroCombat;

final class FixedPoint
{
    public const SCALE = 1_000_000;

    public static function parse(string|int $value): int
    {
        $text = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $text, $matches)) {
            throw new \InvalidArgumentException('Expected a non-negative decimal with at most six fractional digits.');
        }
        $whole = (int) $matches[1];
        if ((string) $whole !== ltrim($matches[1], '0') && '0' !== $matches[1]) {
            throw new NumericOverflow();
        }
        $micro = self::checkedMultiply($whole, self::SCALE);
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 6, '0') : 0;

        return self::checkedAdd($micro, $fraction);
    }

    public static function format(int $micro): string
    {
        if ($micro < 0) {
            throw new \InvalidArgumentException('Fixed-point values cannot be negative.');
        }
        $whole = intdiv($micro, self::SCALE);
        $fraction = $micro % self::SCALE;

        return 0 === $fraction ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $fraction, 6, '0', STR_PAD_LEFT), '0');
    }

    public static function mulDivNearest(int $x, int $y, int $divisor): int
    {
        if ($x < 0 || $y < 0 || $divisor <= 0) {
            throw new \InvalidArgumentException('mulDivNearest expects non-negative operands and a positive divisor.');
        }
        $quotient = intdiv($x, $divisor);
        $remainder = $x % $divisor;
        $whole = self::checkedMultiply($quotient, $y);
        $fraction = self::roundDivNearest(self::checkedMultiply($remainder, $y), $divisor);

        return self::checkedAdd($whole, $fraction);
    }

    public static function roundDivNearestSigned(int $numerator, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('The divisor must be positive.');
        }
        if ($numerator >= 0) {
            return self::roundDivNearest($numerator, $divisor);
        }
        if (PHP_INT_MIN === $numerator) {
            throw new NumericOverflow();
        }

        return -self::roundDivNearest(-$numerator, $divisor);
    }

    public static function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException('Checked fixed-point multiplication expects non-negative operands.');
        }
        if (0 !== $left && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new NumericOverflow();
        }

        return $left * $right;
    }

    public static function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException('Checked fixed-point addition expects non-negative operands.');
        }
        if ($right > PHP_INT_MAX - $left) {
            throw new NumericOverflow();
        }

        return $left + $right;
    }

    private static function roundDivNearest(int $numerator, int $divisor): int
    {
        $quotient = intdiv($numerator, $divisor);
        $remainder = $numerator % $divisor;
        $roundUpThreshold = intdiv($divisor, 2) + ($divisor % 2);

        return $remainder >= $roundUpThreshold ? self::checkedAdd($quotient, 1) : $quotient;
    }
}
