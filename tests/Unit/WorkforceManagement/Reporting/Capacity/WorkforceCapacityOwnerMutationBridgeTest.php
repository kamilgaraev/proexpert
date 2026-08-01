<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureResult;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityOwnerMutationBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityOwnerMutationBridgeTest extends TestCase
{
    #[Test]
    public function every_capacity_owner_table_maps_to_the_closed_source_contract(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture);
        $cases = [
            ['workforce_staff_units', 'staff_unit', ['id' => 11, 'valid_from' => '2026-01-01']],
            ['workforce_employee_assignments', 'assignment', ['id' => 12, 'staff_unit_id' => 11, 'valid_from' => '2026-01-01']],
            ['workforce_work_schedules', 'schedule', ['id' => 13, 'is_active' => true]],
            ['workforce_work_schedule_days', 'schedule_day', ['id' => 14, 'work_schedule_id' => 13, 'work_date' => '2026-08-15']],
            ['workforce_absences', 'absence', ['id' => 15, 'status' => 'approved', 'start_date' => '2026-08-15', 'end_date' => '2026-08-15']],
            ['workforce_business_trips', 'business_trip', ['id' => 16, 'status' => 'approved', 'start_date' => '2026-08-15', 'end_date' => '2026-08-15']],
        ];

        foreach ($cases as [$table, $type, $state]) {
            $bridge->afterMutation($table, 7, null, ['organization_id' => 7, ...$state]);
            self::assertSame($type, $capture->commands[array_key_last($capture->commands)]->sourceType);
        }

        self::assertCount(6, $capture->commands);
    }

    #[Test]
    public function draft_unavailability_is_skipped_but_approved_to_cancelled_transition_is_captured(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture);

        $bridge->afterMutation('workforce_absences', 7, null, [
            'id' => 15,
            'organization_id' => 7,
            'employee_id' => 41,
            'status' => 'draft',
            'comment' => 'medical details',
        ]);
        self::assertCount(0, $capture->commands);

        $bridge->afterMutation(
            'workforce_absences',
            7,
            [
                'id' => 15,
                'organization_id' => 7,
                'employee_id' => 41,
                'status' => 'approved',
                'comment' => 'medical details',
            ],
            [
                'id' => 15,
                'organization_id' => 7,
                'employee_id' => 41,
                'status' => 'cancelled',
                'comment' => 'medical details',
            ],
        );

        self::assertCount(1, $capture->commands);
        $serialized = json_encode($capture->commands[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('medical details', $serialized);
        self::assertStringNotContainsString('comment', $serialized);
    }

    #[Test]
    public function dismissal_uses_lifecycle_contract_with_exact_closed_assignment_states(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture);

        $bridge->afterEmployeeDismissal(
            organizationId: 7,
            employeeId: 41,
            dismissalDate: '2026-08-15',
            oldAssignments: [[
                'id' => 31,
                'organization_id' => 7,
                'employee_id' => 41,
                'staff_unit_id' => 11,
                'project_id' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'status' => 'active',
            ]],
            newAssignments: [[
                'id' => 31,
                'organization_id' => 7,
                'employee_id' => 41,
                'staff_unit_id' => 11,
                'project_id' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-08-15',
                'status' => 'active',
            ]],
        );

        self::assertCount(1, $capture->commands);
        self::assertSame('employee_lifecycle', $capture->commands[0]->sourceType);
        self::assertSame('2026-08-15', $capture->commands[0]->newState['dismissal_date']);
        self::assertCount(1, $capture->commands[0]->oldState['assignments']);
        self::assertCount(1, $capture->commands[0]->newState['assignments']);
    }
}

final class RecordingCaptureBoundary implements WorkforceCapacityCaptureBoundary
{
    public array $commands = [];

    public function capture(WorkforceCapacityCaptureCommand $command): WorkforceCapacityCaptureResult
    {
        $this->commands[] = $command;

        return new WorkforceCapacityCaptureResult(1, 1, str_repeat('a', 64));
    }
}
