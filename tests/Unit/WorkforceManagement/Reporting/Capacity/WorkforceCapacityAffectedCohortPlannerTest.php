<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityAffectedCohortPlanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityAffectedCohortPlannerTest extends TestCase
{
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
}
