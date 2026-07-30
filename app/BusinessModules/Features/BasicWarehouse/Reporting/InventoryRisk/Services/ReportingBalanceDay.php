<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use DateTimeInterface;
use DateTimeZone;

final readonly class ReportingBalanceDay
{
    public static function resolve(DateTimeInterface $occurredAt, DateTimeZone $timezone): string
    {
        return (new \DateTimeImmutable($occurredAt->format(DATE_ATOM)))
            ->setTimezone($timezone)
            ->format('Y-m-d');
    }
}
