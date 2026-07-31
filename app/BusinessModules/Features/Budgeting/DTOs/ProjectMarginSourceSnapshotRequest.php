<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProjectMarginSourceSnapshotRequest
{
    public function __construct(
        public ReportScope $scope,
        public array $filters,
        public string $closeId,
        public BudgetingReportSourceCloseIdentity $closeIdentity,
        public DateTimeImmutable $asOf,
        public ?DateTimeImmutable $staleAt,
        public ?string $snapshotId = null,
    ) {
        if (($filters['organization_id'] ?? null) !== $scope->organizationId
            || $closeIdentity->organizationId !== $scope->organizationId
            || ($filters['period_start'] ?? null) !== $closeIdentity->periodStart
            || ($filters['period_end'] ?? null) !== $closeIdentity->periodEnd
            || ($filters['scenario_uuid'] ?? null) !== $closeIdentity->scenarioIdentity
            || ($filters['budget_version_uuid'] ?? null) !== $closeIdentity->planIdentity
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $closeId) !== 1
            || ($snapshotId !== null && preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_request_invalid');
        }
    }
}
