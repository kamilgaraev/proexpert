<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;
use JsonException;

final class CanonicalPipelineJson
{
    public static function encode(array $value): string
    {
        try {
            return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Pipeline output must be valid JSON.', previous: $exception);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            foreach ($value as $key => $item) {
                if (! is_int($key) && ! is_string($key)) {
                    throw new InvalidArgumentException('Pipeline output keys must be strings or integers.');
                }
                $value[$key] = self::normalize($item);
            }

            return $value;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Pipeline output numbers must be finite.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Pipeline output must contain JSON scalar trees.');
    }
}
