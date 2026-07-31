<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PlanFactSourceSnapshotRequest
{
    public function __construct(
        public ReportScope $scope,
        public array $filters,
        public DateTimeImmutable $asOf,
        public ?DateTimeImmutable $staleAt,
        public ?string $snapshotId = null,
    ) {
        if (($filters['organization_id'] ?? null) !== $scope->organizationId
            || (($filters['project_id'] ?? null) !== null && !in_array((int) $filters['project_id'], $scope->projectIds, true))
            || ($snapshotId !== null && preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_request_invalid');
        }
    }
}
