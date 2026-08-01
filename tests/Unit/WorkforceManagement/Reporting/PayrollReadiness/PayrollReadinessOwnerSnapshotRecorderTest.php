<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessEvidenceSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessSnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessOwnerSnapshotRecorder;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessSnapshotBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessOwnerSnapshotRecorderTest extends TestCase
{
    public function test_owner_boundary_appends_blocked_snapshot_from_exact_supplied_period_identity(): void
    {
        $source = new InMemoryPayrollReadinessEvidenceSource(
            sourceRows: [[
                'id' => 501,
                'organization_id' => 10,
                'payroll_period_id' => 20,
                'employee_id' => 900,
                'project_id' => 30,
                'work_order_id' => 61,
                'work_order_line_id' => 62,
                'timesheet_entry_id' => 63,
                'work_date' => '2026-07-10',
                'source_type' => 'timesheet_hours',
                'hours' => '7.5',
                'amount' => '1000',
                'payload' => null,
            ]],
            issues: [[
                'id' => 801,
                'organization_id' => 10,
                'payroll_period_id' => 20,
                'severity' => 'blocking',
                'issue_code' => 'missing_accounting_mapping',
                'entity_type' => 'payroll_source_row',
                'entity_id' => 501,
                'employee_id' => 900,
                'project_id' => 30,
                'payload' => null,
                'resolved_at' => null,
            ]],
        );
        $store = new InMemoryPayrollReadinessSnapshotStore;
        $recorder = new PayrollReadinessOwnerSnapshotRecorder(
            new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()),
            $source,
            $store,
        );

        $snapshot = $recorder->recordBlocked(
            period: new PayrollReadinessPeriodIdentity(10, 20, 30, '2026-07-01', '2026-07-31'),
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::ACCOUNTING_BLOCKERS,
        );

        self::assertSame([$snapshot], $store->snapshots);
        self::assertSame(['missing_accounting_mapping'], $snapshot->blockerCodes);
        self::assertSame(10, $source->requestedPeriod?->organizationId);
        self::assertSame(20, $source->requestedPeriod?->periodId);
    }

    public function test_owner_boundary_does_not_read_validation_issues_for_successful_lock(): void
    {
        $source = new InMemoryPayrollReadinessEvidenceSource(sourceRows: [[
            'id' => 501,
            'organization_id' => 10,
            'payroll_period_id' => 20,
            'employee_id' => 900,
            'project_id' => 30,
            'work_order_id' => null,
            'work_order_line_id' => null,
            'timesheet_entry_id' => 63,
            'work_date' => '2026-07-10',
            'source_type' => 'timesheet_hours',
            'hours' => '7.5',
            'amount' => '1000',
            'payload' => null,
        ]], issues: []);
        $store = new InMemoryPayrollReadinessSnapshotStore;
        $recorder = new PayrollReadinessOwnerSnapshotRecorder(
            new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()),
            $source,
            $store,
        );

        $snapshot = $recorder->recordLocked(
            period: new PayrollReadinessPeriodIdentity(10, 20, null, '2026-07-01', '2026-07-31'),
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            lockedSourceHash: str_repeat('b', 64),
        );

        self::assertSame([$snapshot], $store->snapshots);
        self::assertSame(0, $source->validationIssueReads);
    }
}

final class InMemoryPayrollReadinessEvidenceSource implements PayrollReadinessEvidenceSource
{
    public ?PayrollReadinessPeriodIdentity $requestedPeriod = null;

    public int $validationIssueReads = 0;

    public function __construct(private array $sourceRows, private array $issues) {}

    public function sourceRows(PayrollReadinessPeriodIdentity $period): array
    {
        $this->requestedPeriod = $period;

        return $this->sourceRows;
    }

    public function validationIssues(PayrollReadinessPeriodIdentity $period): array
    {
        $this->requestedPeriod = $period;
        $this->validationIssueReads++;

        return $this->issues;
    }
}

final class InMemoryPayrollReadinessSnapshotStore implements PayrollReadinessSnapshotStore
{
    public array $snapshots = [];

    public function append(PayrollReadinessSnapshot $snapshot, iterable $items): void
    {
        iterator_to_array($items);
        $this->snapshots[] = $snapshot;
    }
}
