<?php

declare(strict_types=1);

namespace App\Enums;

enum CurrencyCode: string
{
    case RUB = 'RUB';
    case USD = 'USD';
    case EUR = 'EUR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $currency) {
            $options[$currency->value] = $currency->value;
        }

        return $options;
    }
}
