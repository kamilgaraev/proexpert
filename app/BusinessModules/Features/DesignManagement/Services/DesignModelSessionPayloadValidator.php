<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

final class DesignModelSessionPayloadValidator
{
    public static function coordinate(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    public static function vector(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === [0, 1, 2]
            && count(array_filter($value, self::coordinate(...))) === 3;
    }

    public static function camera(mixed $value, bool $legacy = false): bool
    {
        if (! is_array($value) || ! self::vector($value['position'] ?? null) || ! self::vector($value['target'] ?? null)) {
            return false;
        }
        $keys = ['position', 'target', 'projection', 'up', 'fov', 'zoom', 'aspect'];
        if (array_diff(array_keys($value), $keys) !== []) {
            return false;
        }
        if ($legacy && count($value) === 2) {
            return true;
        }

        return array_diff($keys, array_keys($value)) === []
            && in_array($value['projection'], ['perspective', 'orthographic'], true)
            && self::vector($value['up']) && array_sum(array_map(static fn ($v): float => (float) $v ** 2, $value['up'])) > 0
            && self::coordinate($value['fov']) && $value['fov'] > 0 && $value['fov'] < 180
            && self::coordinate($value['zoom']) && $value['zoom'] > 0
            && self::coordinate($value['aspect']) && $value['aspect'] > 0;
    }

    public static function elementId(mixed $value, bool $legacy = false): bool
    {
        if ($legacy && is_string($value) && $value !== '' && strlen($value) <= 255) {
            return true;
        }

        return (is_int($value) || (is_string($value) && strlen($value) <= 16 && ctype_digit($value)))
            && (int) $value > 0 && (int) $value <= 9007199254740991;
    }

    public static function selection(mixed $value, bool $legacy = false): bool
    {
        return is_array($value) && array_diff(array_keys($value), ['model_version_id', 'element_id']) === []
            && array_key_exists('model_version_id', $value) && array_key_exists('element_id', $value)
            && (($value['model_version_id'] === null && $value['element_id'] === null)
                || (is_int($value['model_version_id']) && $value['model_version_id'] > 0
                    && ($value['element_id'] === null || self::elementId($value['element_id'], $legacy))));
    }
}
