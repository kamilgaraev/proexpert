<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;
use TypeError;

final class BuildingModelSchema
{
    public const MAX_FLOORS = 100;

    public const MAX_ELEMENTS = 10000;

    public const MAX_VERTICES = 2048;

    public const MAX_COORDINATE_M = 1000000.0;

    public const MAX_JSON_BYTES = 4194304;

    public static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Building model requires exact keys.');
        }
    }

    public static function typed(callable $factory): mixed
    {
        try {
            return $factory();
        } catch (TypeError $error) {
            throw new InvalidArgumentException('Building model contains an invalid value type.', previous: $error);
        }
    }

    public static function key(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }

        return $value;
    }

    public static function nullableLabel(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '' || strlen($value) > 100 || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/^[\p{L}\p{N} _-]+$/u', trim($value)) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }

        return trim($value);
    }

    public static function confidence(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('Confidence must be numeric.');
        }
        $value = (float) $value;
        if (! is_finite($value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('Confidence must be between zero and one.');
        }

        return round($value, 6);
    }

    public static function certainty(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['confirmed', 'estimated', 'unknown'], true)) {
            throw new InvalidArgumentException('Geometry certainty is invalid.');
        }

        return $value;
    }

    public static function evidenceIds(mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new InvalidArgumentException('Evidence IDs must be a non-empty list.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Evidence IDs must be positive integers.');
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    public static function nullableMetric(mixed $value, string $field, bool $positive = false): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("{$field} must be numeric.");
        }
        $metric = (float) $value;
        if (! is_finite($metric) || ($positive ? $metric <= 0 : abs($metric) > self::MAX_COORDINATE_M)) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }
        if ($positive && $metric > self::MAX_COORDINATE_M) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }

        return round($metric, 6);
    }

    public static function nullablePoint(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
            throw new InvalidArgumentException("{$field} must be a 2D point.");
        }

        return [
            self::coordinate($value[0], $field),
            self::coordinate($value[1], $field),
        ];
    }

    public static function nullablePolygon(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Room polygon must be a list.');
        }
        $points = array_map(static fn (mixed $point): array => self::nullablePoint($point, 'polygon point')
            ?? throw new InvalidArgumentException('Room polygon point is absent.'), $value);
        if (count($points) > 1 && $points[0] === $points[array_key_last($points)]) {
            array_pop($points);
        }
        if (count($points) < 3 || count($points) > self::MAX_VERTICES) {
            throw new InvalidArgumentException('Room polygon vertex count is invalid.');
        }
        $scaled = array_map(self::scaledPoint(...), $points);
        $unique = [];
        foreach ($scaled as $index => $point) {
            $key = implode(':', $point);
            if (isset($unique[$key])) {
                $previous = $unique[$key];
                if ($index === $previous + 1 || ($previous === 0 && $index === count($scaled) - 1)) {
                    throw new InvalidArgumentException('Room polygon contains a zero-length edge.');
                }
                throw new InvalidArgumentException('Room polygon contains a repeated non-adjacent vertex.');
            }
            $unique[$key] = $index;
        }
        if (count($unique) < 3) {
            throw new InvalidArgumentException('Room polygon is degenerate.');
        }
        if (self::selfIntersects($scaled)) {
            throw new InvalidArgumentException('Room polygon must not self-intersect.');
        }
        $areaSign = self::polygonAreaSign($scaled);
        if ($areaSign === 0) {
            throw new InvalidArgumentException('Room polygon is collinear.');
        }
        if ($areaSign < 0) {
            $points = array_reverse($points);
        }
        $origin = 0;
        foreach ($points as $index => $point) {
            if ($point[0] < $points[$origin][0] || ($point[0] === $points[$origin][0] && $point[1] < $points[$origin][1])) {
                $origin = $index;
            }
        }

        return array_values(array_merge(array_slice($points, $origin), array_slice($points, 0, $origin)));
    }

    public static function assertScaleCertainty(string $scaleStatus, string $certainty, bool $hasMetric): void
    {
        if ($scaleStatus === 'unknown' && ($hasMetric || $certainty !== 'unknown')) {
            throw new InvalidArgumentException('Unknown scale cannot contain metric geometry.');
        }
        if ($scaleStatus === 'estimated' && $hasMetric && $certainty !== 'estimated') {
            throw new InvalidArgumentException('Estimated scale requires estimated geometry certainty.');
        }
    }

    public static function canonicalJson(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function coordinate(mixed $value, string $field): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("{$field} coordinate must be numeric.");
        }
        $value = (float) $value;
        if (! is_finite($value) || abs($value) > self::MAX_COORDINATE_M) {
            throw new InvalidArgumentException("{$field} coordinate is invalid.");
        }

        return round($value, 6);
    }

    private static function polygonAreaSign(array $points): int
    {
        $area = '0';
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            $left = bcmul((string) $points[$i][0], (string) $points[$next][1], 0);
            $right = bcmul((string) $points[$next][0], (string) $points[$i][1], 0);
            $area = bcadd($area, bcsub($left, $right, 0), 0);
        }

        return bccomp($area, '0', 0);
    }

    private static function scaledPoint(array $point): array
    {
        return [(int) round($point[0] * 1000000), (int) round($point[1] * 1000000)];
    }

    private static function selfIntersects(array $points): bool
    {
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $a1 = $points[$i];
            $a2 = $points[($i + 1) % $count];
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i || $j === ($i + 1) % $count || ($j + 1) % $count === $i) {
                    continue;
                }
                if (self::segmentsIntersect($a1, $a2, $points[$j], $points[($j + 1) % $count])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $abC = self::orientation($a, $b, $c);
        $abD = self::orientation($a, $b, $d);
        $cdA = self::orientation($c, $d, $a);
        $cdB = self::orientation($c, $d, $b);
        if ($abC !== $abD && $cdA !== $cdB && $abC !== 0 && $abD !== 0 && $cdA !== 0 && $cdB !== 0) {
            return true;
        }

        return ($abC === 0 && self::onSegment($a, $b, $c))
            || ($abD === 0 && self::onSegment($a, $b, $d))
            || ($cdA === 0 && self::onSegment($c, $d, $a))
            || ($cdB === 0 && self::onSegment($c, $d, $b));
    }

    private static function orientation(array $a, array $b, array $c): int
    {
        $left = bcmul((string) ($b[0] - $a[0]), (string) ($c[1] - $a[1]), 0);
        $right = bcmul((string) ($b[1] - $a[1]), (string) ($c[0] - $a[0]), 0);

        return bccomp(bcsub($left, $right, 0), '0', 0);
    }

    private static function onSegment(array $a, array $b, array $point): bool
    {
        return $point[0] >= min($a[0], $b[0]) && $point[0] <= max($a[0], $b[0])
            && $point[1] >= min($a[1], $b[1]) && $point[1] <= max($a[1], $b[1]);
    }
}
