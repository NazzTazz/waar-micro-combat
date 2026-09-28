<?php

namespace Waar\MicroCombat\Bagaar;

/** Declarative, bounded controls exposed by a Lua policy. */
final class LuaParameters
{
    public static function schema(mixed $raw): array
    {
        if (!is_array($raw) || count($raw) > 16 || ($raw !== [] && !array_is_list($raw))) {
            throw new \InvalidArgumentException('Description des paramètres Lua invalide.');
        }
        $result = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)
                || !preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $entry['name'])
                || isset($result[$entry['name']])
                || !is_string($entry['description'] ?? '') || strlen($entry['description'] ?? '') > 240) {
                throw new \InvalidArgumentException('Paramètre Lua invalide ou répété.');
            }
            $name = $entry['name'];
            $type = $entry['type'] ?? null;
            if ($type === 'toggle') {
                if (!is_bool($entry['default'] ?? null)) {
                    throw new \InvalidArgumentException('Valeur par défaut Lua invalide.');
                }
                $result[$name] = ['name' => $name, 'type' => 'toggle', 'default' => $entry['default'],
                    'description' => $entry['description'] ?? ''];
            } elseif ($type === 'slider') {
                foreach (['min', 'max', 'step', 'default'] as $field) {
                    if (!is_int($entry[$field] ?? null) && !is_float($entry[$field] ?? null)) {
                        throw new \InvalidArgumentException('Bornes Lua invalides.');
                    }
                }
                $min = $entry['min']; $max = $entry['max']; $step = $entry['step']; $default = $entry['default'];
                if (!is_finite((float)$min) || !is_finite((float)$max) || !is_finite((float)$step)
                    || $min < -1_000_000 || $max > 1_000_000 || $max <= $min || $step <= 0
                    || $default < $min || $default > $max
                    || abs(($default - $min) / $step - round(($default - $min) / $step)) > 1e-7) {
                    throw new \InvalidArgumentException('Bornes Lua invalides.');
                }
                $result[$name] = ['name' => $name, 'type' => 'slider', 'min' => $min, 'max' => $max,
                    'step' => $step, 'default' => $default, 'description' => $entry['description'] ?? ''];
            } else {
                throw new \InvalidArgumentException('Type de paramètre Lua inconnu.');
            }
        }
        return array_values($result);
    }

    public static function values(array $schema, mixed $submitted): array
    {
        if ($submitted === null) $submitted = [];
        if (!is_array($submitted) || ($submitted !== [] && array_is_list($submitted))) {
            throw new \InvalidArgumentException('Réglages Lua invalides.');
        }
        $values = [];
        foreach ($schema as $entry) {
            $name = $entry['name'];
            $value = $submitted[$name] ?? $entry['default'];
            if ($entry['type'] === 'toggle') {
                if (!is_bool($value)) throw new \InvalidArgumentException('Réglage Lua invalide : '.$name);
            } elseif ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
                || $value < $entry['min'] || $value > $entry['max']
                || abs(($value - $entry['min']) / $entry['step'] - round(($value - $entry['min']) / $entry['step'])) > 1e-7) {
                throw new \InvalidArgumentException('Réglage Lua invalide : '.$name);
            }
            $values[$name] = $value;
        }
        if (array_diff(array_keys($submitted), array_keys($values)) !== []) {
            throw new \InvalidArgumentException('Réglage Lua inconnu.');
        }
        return $values;
    }
}
