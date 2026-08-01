<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityRangeDescriptor;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityAffectedCohortPlanner;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityAffectedCohortPlannerTest extends TestCase
{
    #[Test]
    public function compact_descriptors_keep_a_long_finite_range_without_month_expansion(): void
    {
        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:long-range',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: null,
            newState: [
                'id' => 31,
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'project_id' => 101,
                'valid_from' => '2026-08-01',
                'valid_to' => '2046-07-31',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $ranges = $planner->describe($command, [], '2026-08-15');

        self::assertCount(2, $ranges);
        self::assertSame('2026-08-01', $ranges[0]->fromMonth);
        self::assertSame('2046-07-01', $ranges[0]->throughMonth);
        self::assertNull($ranges[0]->projectId);
        self::assertSame(101, $ranges[1]->projectId);
    }

    #[Test]
    public function old_and_new_assignment_ranges_preserve_both_projects_and_null_bucket_in_sorted_months(): void
    {
        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:revision-2',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: [
                'id' => 31,
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'project_id' => 101,
                'valid_from' => '2026-08-01',
                'valid_to' => '2026-10-31',
            ],
            newState: [
                'id' => 31,
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'project_id' => 202,
                'valid_from' => '2026-09-01',
                'valid_to' => '2026-09-30',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $keys = iterator_to_array($planner->plan($command, [], '2026-08-15'));

        self::assertSame([
            '7:2026-08-01:11:null',
            '7:2026-08-01:11:101',
            '7:2026-09-01:11:null',
            '7:2026-09-01:11:101',
            '7:2026-09-01:11:202',
            '7:2026-10-01:11:null',
            '7:2026-10-01:11:101',
        ], array_map(static fn ($key): string => $key->identity(), $keys));
        self::assertSame('2026-08-15', $keys[0]->asOfDate);
        self::assertSame('2026-09-30', $keys[2]->asOfDate);
        self::assertSame('2026-10-31', $keys[5]->asOfDate);
    }

    #[Test]
    public function open_ended_range_captures_only_current_boundary_and_leaves_later_months_to_scheduled_close(): void
    {
        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:revision-1',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: null,
            newState: [
                'id' => 31,
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'project_id' => null,
                'valid_from' => '2026-08-01',
                'valid_to' => null,
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $keys = iterator_to_array($planner->plan($command, [], '2026-08-15'));

        self::assertCount(1, $keys);
        self::assertSame('7:2026-08-01:11:null', $keys[0]->identity());
    }

    #[Test]
    public function approved_absence_uses_its_exact_future_range_instead_of_the_open_assignment_range(): void
    {
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'absence:71:approved-revision',
            organizationId: 7,
            sourceType: 'absence',
            oldState: ['id' => 71, 'organization_id' => 7, 'employee_id' => 41, 'status' => 'draft'],
            newState: [
                'id' => 71,
                'organization_id' => 7,
                'employee_id' => 41,
                'start_date' => '2026-09-10',
                'end_date' => '2026-10-05',
                'status' => 'approved',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );
        $assignment = [
            'organization_id' => 7,
            'employee_id' => 41,
            'staff_unit_id' => 11,
            'project_id' => 101,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];

        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $keys = iterator_to_array($planner->plan(
            $command,
            [$assignment],
            '2026-08-15',
        ));

        self::assertSame([
            '7:2026-09-01:11:null',
            '7:2026-09-01:11:101',
            '7:2026-10-01:11:null',
            '7:2026-10-01:11:101',
        ], array_map(static fn ($key): string => $key->identity(), $keys));
        self::assertSame(
            array_map(static fn ($key): string => $key->identity(), $keys),
            $this->expandedDescriptorIdentities($command, $planner->describe($command, [$assignment], '2026-08-15')),
        );
    }

    #[Test]
    public function approved_business_trip_is_limited_to_the_assignment_intersection(): void
    {
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'business-trip:81:approved-revision',
            organizationId: 7,
            sourceType: 'business_trip',
            oldState: null,
            newState: [
                'id' => 81,
                'organization_id' => 7,
                'employee_id' => 41,
                'project_id' => 101,
                'start_date' => '2026-09-10',
                'end_date' => '2026-11-05',
                'status' => 'approved',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );
        $assignment = [
            'organization_id' => 7,
            'employee_id' => 41,
            'staff_unit_id' => 11,
            'project_id' => 101,
            'valid_from' => '2026-10-01',
            'valid_to' => '2026-10-31',
        ];
        $planner = new WorkforceCapacityAffectedCohortPlanner;

        $keys = iterator_to_array($planner->plan($command, [$assignment], '2026-08-15'));

        self::assertSame([
            '7:2026-10-01:11:null',
            '7:2026-10-01:11:101',
        ], array_map(static fn ($key): string => $key->identity(), $keys));
        self::assertSame(
            array_map(static fn ($key): string => $key->identity(), $keys),
            $this->expandedDescriptorIdentities($command, $planner->describe($command, [$assignment], '2026-08-15')),
        );
    }

    #[Test]
    public function future_schedule_day_targets_only_its_own_month(): void
    {
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'schedule-day:61:future-revision',
            organizationId: 7,
            sourceType: 'schedule_day',
            oldState: null,
            newState: [
                'id' => 61,
                'organization_id' => 7,
                'work_schedule_id' => 51,
                'work_date' => '2026-11-02',
                'day_type' => 'holiday',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $assignment = [
            'organization_id' => 7,
            'staff_unit_id' => 11,
            'project_id' => null,
            'work_schedule_id' => 51,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $keys = iterator_to_array($planner->plan($command, [$assignment], '2026-08-15'));

        self::assertCount(1, $keys);
        self::assertSame('7:2026-11-01:11:null', $keys[0]->identity());
        self::assertSame(
            array_map(static fn ($key): string => $key->identity(), $keys),
            $this->expandedDescriptorIdentities($command, $planner->describe($command, [$assignment], '2026-08-15')),
        );
    }

    #[Test]
    public function scheduled_capture_request_has_an_explicit_historical_cohort(): void
    {
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'capture-request:11:2026-07-close',
            organizationId: 7,
            sourceType: 'capture_request',
            oldState: null,
            newState: [
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'project_id' => null,
                'month_start' => '2026-07-01',
                'as_of_date' => '2026-07-31',
            ],
            captureKind: 'scheduled_close',
            actorUserId: null,
            serviceActor: 'workforce-scheduler',
        );

        $planner = new WorkforceCapacityAffectedCohortPlanner;
        $keys = iterator_to_array($planner->plan($command, [], '2026-08-15'));

        self::assertCount(1, $keys);
        self::assertSame('7:2026-07-01:11:null', $keys[0]->identity());
        self::assertSame('2026-07-31', $keys[0]->asOfDate);
        self::assertSame(
            array_map(static fn ($key): string => $key->identity(), $keys),
            $this->expandedDescriptorIdentities($command, $planner->describe($command, [], '2026-08-15')),
        );
    }

    private function expandedDescriptorIdentities(WorkforceCapacityCaptureCommand $command, array $ranges): array
    {
        $keys = [];
        foreach ($ranges as $range) {
            self::assertInstanceOf(WorkforceCapacityRangeDescriptor::class, $range);
            $throughExclusive = (new DateTimeImmutable($range->throughMonth))->add(new DateInterval('P1M'));
            foreach (new DatePeriod(new DateTimeImmutable($range->fromMonth), new DateInterval('P1M'), $throughExclusive) as $month) {
                $monthStart = $month->format('Y-m-01');
                $asOfDate = $monthStart === '2026-08-01'
                    ? '2026-08-15'
                    : $month->modify('last day of this month')->format('Y-m-d');
                $key = new WorkforceCapacityCohortKey(
                    $command->organizationId,
                    $asOfDate,
                    $monthStart,
                    $range->staffUnitId,
                    $range->projectId,
                );
                $keys[$key->sortIdentity()] = $key->identity();
            }
        }
        ksort($keys, SORT_STRING);

        return array_values($keys);
    }
}
