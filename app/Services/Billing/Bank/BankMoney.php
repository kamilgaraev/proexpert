<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class BankMoney
{
    public static function minor(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('bank_amount_invalid');
        }
        $text = (string) $value;
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $text)) {
            throw new InvalidArgumentException('bank_amount_invalid');
        }

        return BigDecimal::of($text)->multipliedBy(100)->toInt();
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('bank_amount_invalid');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
