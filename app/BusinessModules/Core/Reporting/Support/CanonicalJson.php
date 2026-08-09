<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('canonical_json_invalid_value', 0, $exception);
        }

        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_resource($value) || is_object($value)) {
            throw new InvalidArgumentException('canonical_json_unsupported_value');
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('canonical_json_non_finite_float');
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
