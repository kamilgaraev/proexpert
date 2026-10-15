<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use Carbon\CarbonImmutable;

enum CommercialBillingPeriod: string
{
    case ThirtyDays = '30_days';
    case HalfYear = '6_months';
    case Year = '12_months';

    public function months(): int
    {
        return match ($this) {
            self::ThirtyDays => 1,
            self::HalfYear => 6,
            self::Year => 12,
        };
    }

    public function discountPercent(): int
    {
        return match ($this) {
            self::ThirtyDays => 0,
            self::HalfYear => 5,
            self::Year => 10,
        };
    }

    public function endAt(CarbonImmutable $start): CarbonImmutable
    {
        return $this === self::ThirtyDays
            ? $start->addDays(30)
            : $start->setTimezone('Europe/Moscow')->addMonthsNoOverflow($this->months())->setTimezone($start->getTimezone());
    }

    public function amountMinor(int $monthlyAmountMinor): int
    {
        return intdiv($monthlyAmountMinor * $this->months() * (100 - $this->discountPercent()) + 50, 100);
    }
}
