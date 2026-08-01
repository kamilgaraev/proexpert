<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityLifecycleCaptureCoordinator;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureResult;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityLifecycleCaptureDraft;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityOwnerMutationBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityOwnerMutationBridgeTest extends TestCase
{
    #[Test]
    public function every_capacity_owner_table_maps_to_the_closed_source_contract(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture, new RecordingLifecycleCaptureCoordinator);
        $cases = [
            ['workforce_staff_units', 'staff_unit', ['id' => 11, 'valid_from' => '2026-01-01']],
            ['workforce_employee_assignments', 'assignment', ['id' => 12, 'staff_unit_id' => 11, 'valid_from' => '2026-01-01']],
            ['workforce_work_schedules', 'schedule', ['id' => 13, 'is_active' => true]],
            ['workforce_work_schedule_days', 'schedule_day', ['id' => 14, 'work_schedule_id' => 13, 'work_date' => '2026-08-15']],
            ['workforce_absences', 'absence', ['id' => 15, 'status' => 'approved', 'start_date' => '2026-08-15', 'end_date' => '2026-08-15']],
            ['workforce_business_trips', 'business_trip', ['id' => 16, 'status' => 'approved', 'start_date' => '2026-08-15', 'end_date' => '2026-08-15']],
        ];

        foreach ($cases as [$table, $type, $state]) {
            $bridge->afterMutation($table, 7, null, [
                'organization_id' => 7,
                ...$state,
            ], '2026-08-15 09:00:00.000001+00');
            self::assertSame($type, $capture->commands[array_key_last($capture->commands)]->sourceType);
        }

        self::assertCount(6, $capture->commands);
    }

    #[Test]
    public function draft_unavailability_is_skipped_but_approved_to_cancelled_transition_is_captured(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture, new RecordingLifecycleCaptureCoordinator);

        $bridge->afterMutation('workforce_absences', 7, null, [
            'id' => 15,
            'organization_id' => 7,
            'employee_id' => 41,
            'status' => 'draft',
            'comment' => 'medical details',
        ], '2026-08-15 09:00:00.000001+00');
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
            '2026-08-15 09:01:00.000001+00',
        );

        self::assertCount(1, $capture->commands);
        $serialized = json_encode($capture->commands[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('medical details', $serialized);
        self::assertStringNotContainsString('comment', $serialized);
    }

    #[Test]
    public function dismissal_delegates_an_id_only_lifecycle_draft_without_assignment_arrays(): void
    {
        $capture = new RecordingCaptureBoundary;
        $lifecycle = new RecordingLifecycleCaptureCoordinator;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture, $lifecycle);

        $draft = $bridge->beginDismissal(
            organizationId: 7,
            employeeId: 41,
            dismissalDate: '2026-08-15',
        );
        $bridge->finishDismissal($draft);

        self::assertCount(0, $capture->commands);
        self::assertSame([[7, 41, '2026-08-15']], $lifecycle->begun);
        self::assertSame([$draft], $lifecycle->finished);
        self::assertSame(7, $draft->organizationId);
        self::assertSame(41, $draft->employeeId);
        self::assertSame('2026-08-15', $draft->dismissalDate);
        self::assertStringNotContainsString('assignments', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function row_version_is_a_stable_idempotency_key_and_distinguishes_later_occurrences(): void
    {
        $capture = new RecordingCaptureBoundary;
        $bridge = new WorkforceCapacityOwnerMutationBridge($capture, new RecordingLifecycleCaptureCoordinator);
        $oldState = [
            'id' => 12,
            'organization_id' => 7,
            'staff_unit_id' => 11,
            'project_id' => 21,
            'rate' => '1.0000',
        ];
        $firstOccurrence = [...$oldState, 'rate' => '0.7500'];

        $bridge->afterMutation(
            'workforce_employee_assignments', 7, $oldState, $firstOccurrence,
            '2026-08-15 09:01:00.000001+00',
        );
        $bridge->afterMutation(
            'workforce_employee_assignments', 7, $oldState, $firstOccurrence,
            '2026-08-15 09:01:00.000001+00',
        );
        $bridge->afterMutation(
            'workforce_employee_assignments', 7, $oldState, $firstOccurrence,
            '2026-08-15 10:01:00.000001+00',
        );

        self::assertCount(3, $capture->commands);
        self::assertSame($capture->commands[0]->mutationId, $capture->commands[1]->mutationId);
        self::assertNotSame($capture->commands[0]->mutationId, $capture->commands[2]->mutationId);
        self::assertMatchesRegularExpression(
            '/^assignment:12:[a-f0-9]{64}$/D',
            $capture->commands[0]->mutationId,
        );
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

final class RecordingLifecycleCaptureCoordinator implements WorkforceCapacityLifecycleCaptureCoordinator
{
    public array $begun = [];

    public array $finished = [];

    public function beginDismissal(
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): WorkforceCapacityLifecycleCaptureDraft {
        $this->begun[] = [$organizationId, $employeeId, $dismissalDate];

        return new WorkforceCapacityLifecycleCaptureDraft(1, $organizationId, $employeeId, $dismissalDate, 0);
    }

    public function finishDismissal(WorkforceCapacityLifecycleCaptureDraft $draft): void
    {
        $this->finished[] = $draft;
    }
}
