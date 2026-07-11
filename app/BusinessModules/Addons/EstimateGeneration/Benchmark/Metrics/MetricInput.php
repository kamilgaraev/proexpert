<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

use InvalidArgumentException;

final class MetricInput
{
    /** @param array<string, mixed> $payload @return list<string> */
    public static function stringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            throw new InvalidArgumentException('metric_input_invalid:'.$key);
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '' || strlen($item) > 128) {
                throw new InvalidArgumentException('metric_input_invalid:'.$key);
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }

    /** @param array<string, mixed> $payload @return array<string, float> */
    public static function decimalMap(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            throw new InvalidArgumentException('metric_input_invalid:'.$key);
        }
        $result = [];
        foreach ($value as $id => $amount) {
            if (! is_string($id) || $id === '' || (! is_string($amount) && ! is_int($amount) && ! is_float($amount))) {
                throw new InvalidArgumentException('metric_input_invalid:'.$key);
            }
            $number = filter_var($amount, FILTER_VALIDATE_FLOAT);
            if ($number === false || ! is_finite((float) $number) || (float) $number < 0.0) {
                throw new InvalidArgumentException('metric_input_invalid:'.$key);
            }
            $result[$id] = (float) $number;
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    public static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (! is_string($value) || $value === '' || strlen($value) > 128) {
            throw new InvalidArgumentException('metric_input_invalid:'.$key);
        }

        return $value;
    }
}
