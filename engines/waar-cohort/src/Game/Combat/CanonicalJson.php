<?php

namespace App\Game\Combat;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        self::sort($value);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return $json;
    }
    private static function sort(mixed &$value): void
    {
        if (!is_array($value)) {
            return;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$entry) {
            self::sort($entry);
        }
        unset($entry);
    }
}
