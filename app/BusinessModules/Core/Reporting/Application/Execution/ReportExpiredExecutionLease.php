<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReportExpiredExecutionLease
{
    public function __construct(
        public string $aggregateKind,
        public string $aggregateId,
        public int $organizationId,
        public string $expectedLeaseToken,
        public DateTimeImmutable $leaseExpiresAt,
    ) {
        if (
            ! in_array($aggregateKind, ['run', 'export'], true)
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $aggregateId) !== 1
            || $organizationId < 1
            || ! Str::isUuid($expectedLeaseToken)
            || $expectedLeaseToken !== strtolower($expectedLeaseToken)
        ) {
            throw new InvalidArgumentException('report_expired_execution_lease_invalid');
        }
    }
}
