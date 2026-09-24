<?php

namespace App\Game\Combat\Numeric;

/**
 * Canonical fixed-point representation for physical combat values.
 *
 * Public domain objects keep floats for ergonomic compatibility, but every
 * value and operation crosses this six-decimal integer boundary.
 */
final class CombatFixedPoint
{
    public const VERSION = 'microstructure-6-half-up-v1';
    public const SCALE = 1_000_000;
    public const DECIMALS = 6;

    public static function canonicalize(float|int|string $value): float
    {
        return self::toFloat(self::units($value));
    }

    public static function units(float|int|string $value): int
    {
        if (is_int($value)) {
            return self::checkedMultiply($value, self::SCALE);
        }

        $decimal = is_float($value) ? sprintf('%.12F', $value) : trim($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $matches)) {
            throw new \InvalidArgumentException('Combat physical values must be plain decimal numbers.');
        }
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > self::DECIMALS && '' !== trim(substr($fraction, self::DECIMALS), '0')) {
            throw new \InvalidArgumentException(sprintf('Combat physical values support at most %d decimal places.', self::DECIMALS));
        }
        $wholeUnits = self::checkedMultiply((int) $matches[2], self::SCALE);
        $fractionUnits = (int) str_pad(substr($fraction, 0, self::DECIMALS), self::DECIMALS, '0');
        $units = $wholeUnits + $fractionUnits;

        return '-' === $matches[1] ? -$units : $units;
    }

    public static function multiply(float|int|string $left, float|int|string $right): float
    {
        $product = self::checkedMultiply(self::units($left), self::units($right));
        $rounded = intdiv(abs($product) + intdiv(self::SCALE, 2), self::SCALE);

        return self::toFloat($product < 0 ? -$rounded : $rounded);
    }

    public static function divideByInt(float|int|string $value, int $divisor): float
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('A fixed-point divisor must be strictly positive.');
        }
        $units = self::units($value);
        $rounded = intdiv(abs($units) + intdiv($divisor, 2), $divisor);

        return self::toFloat($units < 0 ? -$rounded : $rounded);
    }

    /** Compare leftA*leftB and rightA*rightB without overflowing native integers. */
    public static function compareProducts(int $leftA, int $leftB, int $rightA, int $rightB): int
    {
        foreach ([$leftA, $leftB, $rightA, $rightB] as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Product comparison expects non-negative integers.');
            }
        }
        $left = ltrim(self::multiplyDecimalStrings((string) $leftA, (string) $leftB), '0') ?: '0';
        $right = ltrim(self::multiplyDecimalStrings((string) $rightA, (string) $rightB), '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** @param list<float|int|string> $values */
    public static function multiplyManyUnits(array $values): int
    {
        if ([] === $values) {
            throw new \InvalidArgumentException('A fixed-point product needs at least one value.');
        }
        $product = '1';
        foreach ($values as $value) {
            $units = self::units($value);
            if ($units < 0) {
                throw new \InvalidArgumentException('Modifier products cannot be negative.');
            }
            $product = self::multiplyDecimalStrings($product, (string) $units);
        }
        $discard = self::DECIMALS * (count($values) - 1);
        if ($discard > 0) {
            $product = str_pad($product, $discard + 1, '0', STR_PAD_LEFT);
            $kept = substr($product, 0, -$discard);
            if ((int) $product[-$discard] >= 5) {
                $kept = self::incrementDecimalString($kept);
            }
        } else {
            $kept = $product;
        }
        $normalized = ltrim($kept, '0') ?: '0';
        $limit = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($limit) || (strlen($normalized) === strlen($limit) && strcmp($normalized, $limit) > 0)) {
            throw new \OverflowException('Prepared fixed-point value exceeds the supported integer range.');
        }
        return (int) $normalized;
    }

    /** @param list<float|int|string> $values */
    public static function multiplyMany(array $values): float
    {
        return self::multiplyManyUnits($values) / self::SCALE;
    }

    public static function subtractRepeated(float|int|string $value, int $times, float|int|string $subtrahend): float
    {
        if ($times < 0) {
            throw new \InvalidArgumentException('A fixed-point repetition count cannot be negative.');
        }

        return self::toFloat(self::units($value) - self::checkedMultiply($times, self::units($subtrahend)));
    }

    public static function ceilRatio(float|int|string $numerator, float|int|string $denominator): int
    {
        $numeratorUnits = self::units($numerator);
        $denominatorUnits = self::units($denominator);
        if ($numeratorUnits < 0 || $denominatorUnits <= 0) {
            throw new \InvalidArgumentException('ceilRatio expects a positive denominator and a non-negative numerator.');
        }

        return intdiv($numeratorUnits + $denominatorUnits - 1, $denominatorUnits);
    }

    public static function compare(float|int|string $left, float|int|string $right): int
    {
        return self::units($left) <=> self::units($right);
    }

    public static function format(float|int|string $value): string
    {
        return self::formatUnits(self::units($value));
    }

    public static function formatUnits(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);
        $whole = intdiv($absolute, self::SCALE);
        $fraction = rtrim(str_pad((string) ($absolute % self::SCALE), self::DECIMALS, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.('' === $fraction ? '' : '.'.$fraction);
    }

    private static function toFloat(int $units): float
    {
        return $units / self::SCALE;
    }

    private static function checkedMultiply(int $left, int $right): int
    {
        if (0 !== $left && abs($right) > intdiv(PHP_INT_MAX, abs($left))) {
            throw new \OverflowException('Combat fixed-point value exceeds the supported integer range.');
        }

        return $left * $right;
    }

    private static function multiplyDecimalStrings(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            for ($j = strlen($right) - 1; $j >= 0; --$j) {
                $digits[$i + $j + 1] += ((int) $left[$i]) * ((int) $right[$j]);
            }
        }
        for ($i = count($digits) - 1; $i > 0; --$i) {
            $digits[$i - 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private static function incrementDecimalString(string $value): string
    {
        $digits = str_split($value);
        for ($i = count($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }
        return '1'.implode('', $digits);
    }
}
