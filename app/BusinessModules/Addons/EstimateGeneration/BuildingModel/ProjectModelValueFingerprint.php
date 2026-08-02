<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use JsonException;
use InvalidArgumentException;

final class ProjectModelValueFingerprint
{
    /** @param array<string, mixed> $value */
    public static function for(array $value): string
    {
        return hash('sha256', self::json(self::normalize($value)));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                throw new InvalidArgumentException('Project model numeric value is invalid.');
            }

            $decimal = rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');

            return ['number' => $decimal === '-0' ? '0' : $decimal];
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        uksort($value, self::postgresJsonbKeyOrder(...));
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function json(array $value): string
    {
        try {
            return self::encode($value);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Project model value cannot be canonicalized.', previous: $exception);
        }
    }

    private static function encode(mixed $value): string
    {
        if (! is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $items = [];
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $items[] = self::encode($item);
            }

            return '['.implode(', ', $items).']';
        }
        foreach ($value as $key => $item) {
            $items[] = json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).': '.self::encode($item);
        }

        return '{'.implode(', ', $items).'}';
    }

    private static function postgresJsonbKeyOrder(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
