<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Support;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class ProcurementAwardCanonicalizer
{
    private const FORBIDDEN_KEYS = [
        'attachment',
        'attachment_id',
        'attachment_ids',
        'audit_payload',
        'comment',
        'decision_reason',
        'delivery_terms',
        'email',
        'notes',
        'payment_terms',
        'phone',
        'public_token',
        'public_url',
        'raw',
        'supplier_snapshot',
        'token',
        'url',
        'warranty_terms',
    ];

    public static function decimal(mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('procurement_award_float_forbidden');
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('procurement_award_decimal_invalid');
        }

        $raw = trim((string) $value);
        if (preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)$/D', $raw) !== 1) {
            throw new InvalidArgumentException('procurement_award_decimal_invalid');
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative ? substr($raw, 1) : $raw;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }

    public static function add(string ...$values): string
    {
        $scale = 0;
        foreach ($values as $value) {
            $canonical = self::decimal($value);
            $scale = max($scale, strlen(strstr($canonical, '.') ?: '') - 1);
        }

        $sum = 0;
        foreach ($values as $value) {
            $sum += self::minorUnits(self::decimal($value), $scale);
        }

        return self::fromMinorUnits($sum, $scale);
    }

    public static function compare(string $left, string $right): int
    {
        $left = self::decimal($left);
        $right = self::decimal($right);
        $scale = max(
            strlen(strstr($left, '.') ?: '') - 1,
            strlen(strstr($right, '.') ?: '') - 1,
        );

        return self::minorUnits($left, $scale) <=> self::minorUnits($right, $scale);
    }

    public static function hash(array $payload): string
    {
        self::assertSafe($payload);

        return hash('sha256', CanonicalJson::encode($payload));
    }

    public static function framedHash(array $values): string
    {
        $frames = array_map(static function (mixed $value): string {
            if ($value === null) {
                return '-1:';
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException('procurement_award_hash_frame_invalid');
            }
            $string = (string) $value;

            return strlen($string).':'.$string;
        }, $values);

        return hash('sha256', implode('|', $frames));
    }

    public static function assertSafe(array $payload): void
    {
        self::walk($payload);
    }

    private static function walk(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('procurement_award_sensitive_field_forbidden');
            }

            if (is_float($value)) {
                throw new InvalidArgumentException('procurement_award_float_forbidden');
            }

            if (is_array($value)) {
                self::walk($value);
            }
        }
    }

    private static function minorUnits(string $value, int $scale): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $digits = $integer.str_pad($fraction, $scale, '0');
        if (strlen(ltrim($digits, '0')) > 18) {
            throw new InvalidArgumentException('procurement_award_decimal_out_of_range');
        }

        $minor = (int) $digits;

        return $negative ? -$minor : $minor;
    }

    private static function fromMinorUnits(int $value, int $scale): string
    {
        $negative = $value < 0;
        $digits = (string) abs($value);
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
        }

        return self::decimal(($negative ? '-' : '').$digits);
    }
}
