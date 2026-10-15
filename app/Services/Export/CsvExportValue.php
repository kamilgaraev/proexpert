<?php

declare(strict_types=1);

namespace App\Services\Export;

final class CsvExportValue
{
    public static function row(array $values): array
    {
        return array_map(self::literal(...), $values);
    }

    private static function literal(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^-(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1 && is_finite((float) $value)) {
            return $value;
        }

        if (preg_match('/^[\s\p{Z}\p{C}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) === 1
            || preg_match('/^[\x00-\x20\x7F]*[=+\-@]/', $value) === 1
            || str_contains("\t\r\n", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
