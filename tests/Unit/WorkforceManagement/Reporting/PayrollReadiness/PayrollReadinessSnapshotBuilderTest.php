<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessSnapshotKind;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessSnapshotBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessSnapshotBuilderTest extends TestCase
{
    public function test_blocked_snapshot_is_deterministic_redacted_and_explains_each_check(): void
    {
        $builder = new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1());
        $sourceRows = $this->sourceRows();
        $issues = $this->validationIssues();

        $first = $builder->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::VALIDATION_BLOCKERS,
            sourceRows: $sourceRows,
            validationIssues: $issues,
        );
        $second = $builder->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 41,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:20:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::VALIDATION_BLOCKERS,
            sourceRows: array_reverse($sourceRows),
            validationIssues: array_reverse($issues),
        );

        self::assertSame(PayrollReadinessSnapshotKind::PRE_LOCK_BLOCKED, $first->kind);
        self::assertSame(['missing_assignment'], $first->blockerCodes);
        self::assertSame(2, $first->sourceRowCount);
        self::assertSame(1, $first->validationIssueCount);
        self::assertSame(1, $first->blockerCount);
        self::assertSame($first->stateHash, $second->stateHash);
        self::assertNotSame($first->sourceHash, $second->sourceHash);
        self::assertSame(40, $first->actorUserId);
        self::assertSame('2026-08-01T10:15:00+00:00', $first->evaluatedAt->format(DATE_ATOM));

        $checks = [];
        foreach ($first->items() as $item) {
            if ($item->sourceType === 'readiness_check') {
                $checks[$item->code] = $item->status;
            }
        }

        self::assertSame([
            'period_validated' => 'passed',
            'source_present' => 'passed',
            'source_actual' => 'passed',
            'validation_clear' => 'blocked',
            'accounting_clear' => 'not_evaluated',
        ], $checks);

        $persisted = json_encode($first->toPersistence(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Иванов Иван', $persisted);
        self::assertStringNotContainsString('EMP-SECRET', $persisted);
        self::assertStringNotContainsString('125000.00', $persisted);
        self::assertStringNotContainsString('Missing assignment for employee', $persisted);
        $persistedItems = json_encode(array_map(
            static fn ($item): array => $item->toPersistence(1),
            iterator_to_array($first->items(), false),
        ), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"employee_id"', $persistedItems);
        self::assertStringNotContainsString('"hours"', $persistedItems);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->sourceHash);

        $changedRows = $sourceRows;
        $changedRows[0]['amount'] = '125001.00';
        $changed = $builder->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::VALIDATION_BLOCKERS,
            sourceRows: $changedRows,
            validationIssues: $issues,
        );
        self::assertNotSame($first->sourceHash, $changed->sourceHash);
    }

    public function test_locked_snapshot_pins_exact_owner_hash_without_blockers_or_gaps(): void
    {
        $snapshot = (new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()))->locked(
            organizationId: 10,
            periodId: 20,
            projectId: null,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            lockedSourceHash: str_repeat('b', 64),
            sourceRows: $this->sourceRows(),
        );

        self::assertSame(PayrollReadinessSnapshotKind::LOCK_SUCCEEDED, $snapshot->kind);
        self::assertSame(str_repeat('b', 64), $snapshot->ownerSourceHash);
        self::assertSame(str_repeat('b', 64), $snapshot->lockedSourceHash);
        self::assertSame([], $snapshot->blockerCodes);
        self::assertSame([], $snapshot->gapCodes);
        self::assertSame(2, $snapshot->sourceRowCount);
        self::assertSame(0, $snapshot->validationIssueCount);
        self::assertSame(0, $snapshot->blockerCount);
    }

    public function test_non_utc_evaluation_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payroll_readiness_evaluated_at_must_be_utc');

        (new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()))->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T13:15:00+03:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::SOURCE_EMPTY,
            sourceRows: [],
            validationIssues: [],
        );
    }

    public function test_foreign_source_row_is_rejected_before_persistence(): void
    {
        $rows = $this->sourceRows();
        $rows[0]['organization_id'] = 11;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payroll_readiness_source_lineage_mismatch');

        (new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()))->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::SOURCE_CHANGED,
            sourceRows: $rows,
            validationIssues: [],
        );
    }

    public function test_decimal_hashing_preserves_values_above_float_precision(): void
    {
        $builder = new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1());
        $rows = $this->sourceRows();
        $rows[0]['amount'] = '9007199254740992.00';
        $first = $builder->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::SOURCE_CHANGED,
            sourceRows: $rows,
            validationIssues: [],
        );

        $rows[0]['amount'] = '9007199254740993.00';
        $second = $builder->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::SOURCE_CHANGED,
            sourceRows: $rows,
            validationIssues: [],
        );

        self::assertNotSame($first->stateHash, $second->stateHash);
        self::assertNotSame($first->sourceHash, $second->sourceHash);
    }

    public function test_evidence_is_replayed_from_repeatable_stream_instead_of_retained_in_snapshot(): void
    {
        $sourceReads = 0;
        $issueReads = 0;
        $sourceFactory = function () use (&$sourceReads): iterable {
            $sourceReads++;
            yield from array_reverse($this->sourceRows());
        };
        $issueFactory = function () use (&$issueReads): iterable {
            $issueReads++;
            yield from $this->validationIssues();
        };

        $snapshot = (new PayrollReadinessSnapshotBuilder(PayrollReadinessPolicyDefinition::v1()))->blocked(
            organizationId: 10,
            periodId: 20,
            projectId: 30,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: 40,
            evaluatedAt: new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::VALIDATION_BLOCKERS,
            sourceRows: $sourceFactory,
            validationIssues: $issueFactory,
        );

        self::assertSame(1, $sourceReads);
        self::assertSame(1, $issueReads);
        self::assertCount($snapshot->itemCount, iterator_to_array($snapshot->items(), false));
        self::assertSame(2, $sourceReads);
        self::assertSame(2, $issueReads);
    }

    private function sourceRows(): array
    {
        return [
            [
                'id' => 502,
                'organization_id' => 10,
                'payroll_period_id' => 20,
                'employee_id' => 901,
                'project_id' => 30,
                'work_order_id' => 71,
                'work_order_line_id' => 72,
                'timesheet_entry_id' => 73,
                'work_date' => '2026-07-11',
                'source_type' => 'timesheet_hours',
                'hours' => '8.0000',
                'amount' => '125000.00',
                'payload' => ['employee_name' => 'Иванов Иван', 'personnel_number' => 'EMP-SECRET'],
            ],
            [
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
                'hours' => '7.5000',
                'amount' => '1000.00',
                'payload' => null,
            ],
        ];
    }

    private function validationIssues(): array
    {
        return [[
            'id' => 801,
            'organization_id' => 10,
            'payroll_period_id' => 20,
            'severity' => 'blocking',
            'issue_code' => 'missing_assignment',
            'message' => 'Missing assignment for employee',
            'entity_type' => 'payroll_source_row',
            'entity_id' => 501,
            'employee_id' => 900,
            'project_id' => 30,
            'payload' => ['work_date' => '2026-07-10'],
            'resolved_at' => null,
        ]];
    }
}
