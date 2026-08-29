<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacitySnapshotBuilderTest extends TestCase
{
    #[Test]
    public function builds_exact_planned_fte_and_monthly_schedule_without_attendance_or_default_hours(): void
    {
        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $this->source(),
        );

        self::assertSame('2.0000', $snapshot->authorizedFte);
        self::assertSame('1.5000', $snapshot->assignedFte);
        self::assertSame('1.5000', $snapshot->approvedUnavailabilityFte);
        self::assertSame('0.0000', $snapshot->availableFte);
        self::assertSame('0.5000', $snapshot->openFte);
        self::assertSame('0.0000', $snapshot->overallocatedFte);
        self::assertSame('240.00', $snapshot->scheduledHours);
        self::assertSame('unavailable', $snapshot->capacityStatus);
        self::assertSame([], $snapshot->gapCodes);
        self::assertSame('planned_capacity', $snapshot->semanticLabel);
        self::assertSame(64, strlen($snapshot->sourceHash));

        $persistence = $snapshot->toPersistence();
        foreach (['first_name', 'last_name', 'salary', 'destination', 'comment', 'actual_hours', 'overtime'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $persistence);
        }
    }

    #[Test]
    public function missing_schedule_is_a_gap_and_never_becomes_zero_or_eight_hours(): void
    {
        $source = $this->source();
        $source['assignments'][0]['work_schedule_id'] = null;
        $source['schedules'] = [];
        $source['schedule_days'] = [];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertNull($snapshot->scheduledHours);
        self::assertSame('gap', $snapshot->capacityStatus);
        self::assertContains('missing_schedule', $snapshot->gapCodes);
    }

    #[Test]
    public function real_weekend_and_holiday_overrides_are_explicit_zero_hour_days(): void
    {
        $source = $this->source();
        $source['schedule_days'] = [
            [
                'id' => 61,
                'organization_id' => 7,
                'work_schedule_id' => 51,
                'work_date' => '2026-08-03',
                'day_type' => 'holiday',
                'planned_hours' => '0.00',
            ],
            [
                'id' => 62,
                'organization_id' => 7,
                'work_schedule_id' => 51,
                'work_date' => '2026-08-04',
                'day_type' => 'weekend',
                'planned_hours' => '0.00',
            ],
            [
                'id' => 63,
                'organization_id' => 7,
                'work_schedule_id' => 51,
                'work_date' => '2026-08-05',
                'day_type' => 'day_off',
                'planned_hours' => '0.00',
            ],
        ];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('216.00', $snapshot->scheduledHours);
        self::assertSame([], $snapshot->gapCodes);
    }

    #[Test]
    public function production_week_pattern_uses_explicit_work_days_and_hours_per_day(): void
    {
        $source = $this->source();
        $source['schedules'][0]['week_pattern'] = [
            'work_days' => [1, 2, 3, 4, 5],
            'scenario' => '5/2',
        ];
        $source['schedules'][0]['hours_per_day'] = '8.00';

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('240.00', $snapshot->scheduledHours);
        self::assertSame([], $snapshot->gapCodes);
    }

    #[Test]
    public function weekly_schedule_without_stored_pattern_uses_five_day_default(): void
    {
        $source = $this->source();
        $source['schedules'][0]['week_pattern'] = null;
        $source['schedules'][0]['hours_per_day'] = '8.00';

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('240.00', $snapshot->scheduledHours);
        self::assertSame([], $snapshot->gapCodes);
    }

    #[Test]
    public function production_week_pattern_without_explicit_hours_is_an_invalid_schedule_gap(): void
    {
        $source = $this->source();
        $source['schedules'][0]['week_pattern'] = [
            'work_days' => [1, 2, 3, 4, 5],
            'scenario' => '5/2',
        ];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertNull($snapshot->scheduledHours);
        self::assertSame(['invalid_schedule'], $snapshot->gapCodes);
    }

    #[Test]
    public function malformed_explicit_schedule_hours_become_a_gap_instead_of_aborting_capture(): void
    {
        $source = $this->source();
        $source['schedules'][0]['week_pattern'] = ['work_days' => [1, 2, 3, 4, 5]];
        $source['schedules'][0]['hours_per_day'] = 'not-a-decimal';

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertNull($snapshot->scheduledHours);
        self::assertSame(['invalid_schedule'], $snapshot->gapCodes);
    }

    #[Test]
    public function malformed_work_day_override_becomes_a_gap_instead_of_aborting_capture(): void
    {
        $source = $this->source();
        $source['schedule_days'][0]['day_type'] = 'work';
        $source['schedule_days'][0]['planned_hours'] = null;

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertNull($snapshot->scheduledHours);
        self::assertSame(['invalid_schedule'], $snapshot->gapCodes);
    }

    #[Test]
    public function null_project_bucket_is_preserved_and_cross_scope_unavailability_is_not_subtracted(): void
    {
        $source = $this->source();
        $source['assignments'][0]['project_id'] = null;
        $source['business_trips'] = [[
            'id' => 91,
            'organization_id' => 7,
            'employee_id' => 41,
            'project_id' => 999,
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'status' => 'approved',
        ]];
        $source['absences'] = [];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertNull($snapshot->projectId);
        self::assertSame('0.0000', $snapshot->approvedUnavailabilityFte);
        self::assertContains('cross_scope_unavailability', $snapshot->gapCodes);
        self::assertSame('gap', $snapshot->capacityStatus);
    }

    #[Test]
    public function projectless_unavailability_never_reduces_an_arbitrary_project_bucket(): void
    {
        $source = $this->source();
        $source['business_trips'][0]['project_id'] = null;
        $source['absences'] = [[
            'id' => 71,
            'organization_id' => 7,
            'employee_id' => 41,
            'absence_type_id' => 81,
            'affects_payroll' => true,
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'status' => 'approved',
        ]];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('0.0000', $snapshot->approvedUnavailabilityFte);
        self::assertSame('1.5000', $snapshot->availableFte);
        self::assertContains('cross_scope_unavailability', $snapshot->gapCodes);
    }

    #[Test]
    public function soft_deleted_unavailability_never_reduces_capacity(): void
    {
        $source = $this->source();
        $source['assignments'][0]['project_id'] = null;
        $source['business_trips'][0]['project_id'] = null;
        $source['business_trips'][0]['deleted_at'] = '2026-08-15T08:00:00+00:00';
        $source['absences'] = [[
            'id' => 71,
            'organization_id' => 7,
            'employee_id' => 41,
            'absence_type_id' => 81,
            'affects_payroll' => true,
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'status' => 'approved',
            'deleted_at' => '2026-08-15T08:00:00+00:00',
        ]];

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('0.0000', $snapshot->approvedUnavailabilityFte);
        self::assertSame('1.5000', $snapshot->availableFte);
    }

    #[Test]
    public function soft_deleted_staff_unit_is_an_explicit_gap(): void
    {
        $source = $this->source();
        $source['staff_unit']['deleted_at'] = '2026-08-14T09:00:00+00:00';

        $snapshot = $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );

        self::assertSame('gap', $snapshot->capacityStatus);
        self::assertContains('inactive_staff_unit', $snapshot->gapCodes);
    }

    #[Test]
    public function nested_personal_or_compensation_data_is_rejected_before_hashing(): void
    {
        $source = $this->source();
        $source['assignments'][0]['metadata'] = ['nested' => ['salary' => '100000.00']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_restricted_source_field');

        $this->builder()->build(
            key: new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101),
            captureKind: 'change_capture',
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $source,
        );
    }

    private function builder(): WorkforceCapacitySnapshotBuilder
    {
        return new WorkforceCapacitySnapshotBuilder(WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'));
    }

    private function source(): array
    {
        return [
            'staff_unit' => [
                'id' => 11,
                'organization_id' => 7,
                'department_id' => 2,
                'position_id' => 3,
                'headcount' => '2.00',
                'rate' => '1.0000',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
                'deleted_at' => null,
            ],
            'assignments' => [[
                'id' => 31,
                'organization_id' => 7,
                'employee_id' => 41,
                'staff_unit_id' => 11,
                'project_id' => 101,
                'work_schedule_id' => 51,
                'rate' => '1.5000',
                'valid_from' => '2026-08-01',
                'valid_to' => null,
                'status' => 'active',
                'deleted_at' => null,
            ]],
            'schedules' => [[
                'id' => 51,
                'organization_id' => 7,
                'schedule_type' => 'weekly',
                'week_pattern' => [
                    '1' => '8.00', '2' => '8.00', '3' => '8.00', '4' => '8.00', '5' => '8.00',
                    '6' => '0.00', '7' => '0.00',
                ],
                'is_active' => true,
                'deleted_at' => null,
            ]],
            'schedule_days' => [[
                'id' => 61,
                'organization_id' => 7,
                'work_schedule_id' => 51,
                'work_date' => '2026-08-17',
                'day_type' => 'non_work',
                'planned_hours' => '0.00',
            ]],
            'absences' => [],
            'business_trips' => [[
                'id' => 91,
                'organization_id' => 7,
                'employee_id' => 41,
                'project_id' => 101,
                'start_date' => '2026-08-15',
                'end_date' => '2026-08-15',
                'status' => 'approved',
            ]],
            'employee_lifecycle' => [[
                'employee_id' => 41,
                'employment_status' => 'active',
                'dismissal_date' => null,
            ]],
            'gaps' => [],
        ];
    }
}
