<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

final readonly class ReportingBalanceDay
{
    public static function resolve(DateTimeInterface $occurredAt, DateTimeZone $timezone): string
    {
        return (new \DateTimeImmutable($occurredAt->format(DATE_ATOM)))
            ->setTimezone($timezone)
            ->format('Y-m-d');
    }

    public static function utcWindow(string $balanceDate, DateTimeZone $timezone): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $balanceDate, $timezone);
        if ($start === false || $start->format('Y-m-d') !== $balanceDate) {
            throw new DomainException('Inventory balance date is invalid.');
        }

        return [
            $start->setTimezone(new DateTimeZone('UTC')),
            $start->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
        ];
    }
}
