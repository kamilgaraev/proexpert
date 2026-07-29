<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services\Reports;

use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use DateTimeImmutable;
use DomainException;

final readonly class SettlementAgingPolicy
{
    public const VERSION = 'settlement_aging.v1';

    public function bucket(DateTimeImmutable $dueAt, DateTimeImmutable $asOf): SettlementAgingBucket
    {
        if ($dueAt >= $asOf->setTime(0, 0)) {
            return SettlementAgingBucket::NOT_DUE;
        }
        $days = (int) $dueAt->diff($asOf)->format('%a');

        return match (true) {
            $days <= 30 => SettlementAgingBucket::DAYS_1_30,
            $days <= 60 => SettlementAgingBucket::DAYS_31_60,
            $days <= 90 => SettlementAgingBucket::DAYS_61_90,
            default => SettlementAgingBucket::OVER_90,
        };
    }

    public function assertVersion(string $version): void
    {
        if ($version !== self::VERSION) {
            throw new DomainException('settlement_aging_policy_version_mismatch');
        }
    }
}
