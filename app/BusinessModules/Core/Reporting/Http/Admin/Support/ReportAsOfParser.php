<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Support;

use DateTimeImmutable;

final class ReportAsOfParser
{
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/D',
                $value,
            ) !== 1
        ) {
            return null;
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        return $date;
    }
}
