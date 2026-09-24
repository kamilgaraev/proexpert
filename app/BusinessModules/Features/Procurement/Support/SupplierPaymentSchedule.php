<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Support;

use Illuminate\Validation\ValidationException;

use function trans_message;

final class SupplierPaymentSchedule
{
    public static function normalize(mixed $value): ?array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['mode', 'advance_percent', 'deferment_days']) !== []) {
            return null;
        }

        $mode = $value['mode'] ?? null;
        $percent = self::integer($value['advance_percent'] ?? null);
        $days = self::integer($value['deferment_days'] ?? null);
        if ($percent === null || $days === null || $days < 0 || $days > 3650) {
            return null;
        }

        $valid = match ($mode) {
            'postpayment' => $percent === 0,
            'prepayment' => $percent === 100 && $days === 0,
            'mixed' => $percent > 0 && $percent < 100,
            default => false,
        };

        return $valid ? ['mode' => $mode, 'advance_percent' => $percent, 'deferment_days' => $days] : null;
    }

    public static function validated(mixed $value): ?array
    {
        $schedule = self::normalize($value);
        if ($value !== null && $schedule === null) {
            throw ValidationException::withMessages([
                'payment_schedule' => trans_message('procurement.payment_schedule_invalid'),
            ]);
        }

        return $schedule;
    }

    private static function integer(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }
}
