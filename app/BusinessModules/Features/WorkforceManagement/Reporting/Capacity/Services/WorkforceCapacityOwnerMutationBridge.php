<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityLifecycleCaptureCoordinator;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityLifecycleCaptureDraft;
use InvalidArgumentException;

final readonly class WorkforceCapacityOwnerMutationBridge
{
    private const TABLE_TYPES = [
        'workforce_staff_units' => 'staff_unit',
        'workforce_employee_assignments' => 'assignment',
        'workforce_work_schedules' => 'schedule',
        'workforce_work_schedule_days' => 'schedule_day',
        'workforce_absences' => 'absence',
        'workforce_business_trips' => 'business_trip',
    ];

    private const ALLOWED_FIELDS = [
        'staff_unit' => [
            'id', 'organization_id', 'department_id', 'position_id', 'headcount', 'rate',
            'valid_from', 'valid_to', 'is_active', 'deleted_at',
        ],
        'assignment' => [
            'id', 'organization_id', 'employee_id', 'staff_unit_id', 'department_id', 'position_id',
            'project_id', 'work_schedule_id', 'rate', 'valid_from', 'valid_to', 'status', 'deleted_at',
        ],
        'schedule' => [
            'id', 'organization_id', 'schedule_type', 'hours_per_day', 'week_pattern', 'is_active', 'deleted_at',
        ],
        'schedule_day' => [
            'id', 'organization_id', 'work_schedule_id', 'work_date', 'day_type', 'planned_hours',
        ],
        'absence' => [
            'id', 'organization_id', 'employee_id', 'absence_type_id', 'start_date', 'end_date', 'status', 'deleted_at',
        ],
        'business_trip' => [
            'id', 'organization_id', 'employee_id', 'project_id', 'start_date', 'end_date', 'status', 'deleted_at',
        ],
    ];

    public function __construct(
        private WorkforceCapacityCaptureBoundary $capture,
        private WorkforceCapacityLifecycleCaptureCoordinator $lifecycleCapture,
    ) {}

    public function supports(string $table): bool
    {
        return isset(self::TABLE_TYPES[$table]);
    }

    public function afterMutation(
        string $table,
        int $organizationId,
        ?array $oldState,
        ?array $newState,
    ): void {
        $sourceType = self::TABLE_TYPES[$table] ?? null;
        if ($sourceType === null || $organizationId < 1) {
            throw new InvalidArgumentException('workforce_capacity_owner_source_invalid');
        }
        if (in_array($sourceType, ['absence', 'business_trip'], true)
            && ! $this->hasApprovedState($oldState, $newState)) {
            return;
        }

        $oldState = $this->sanitize($sourceType, $organizationId, $oldState);
        $newState = $this->sanitize($sourceType, $organizationId, $newState);
        $this->capture->capture(new WorkforceCapacityCaptureCommand(
            mutationId: $this->mutationId($sourceType, $organizationId, $oldState, $newState),
            organizationId: $organizationId,
            sourceType: $sourceType,
            oldState: $oldState,
            newState: $newState,
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        ));
    }

    public function beginDismissal(
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): WorkforceCapacityLifecycleCaptureDraft {
        if ($organizationId < 1 || $employeeId < 1) {
            throw new InvalidArgumentException('workforce_capacity_lifecycle_identity_invalid');
        }

        return $this->lifecycleCapture->beginDismissal($organizationId, $employeeId, $dismissalDate);
    }

    public function finishDismissal(WorkforceCapacityLifecycleCaptureDraft $draft): void
    {
        $this->lifecycleCapture->finishDismissal($draft);
    }

    private function hasApprovedState(?array $oldState, ?array $newState): bool
    {
        return ($oldState['status'] ?? null) === 'approved' || ($newState['status'] ?? null) === 'approved';
    }

    private function sanitize(string $sourceType, int $organizationId, ?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        if ((int) ($state['organization_id'] ?? 0) !== $organizationId) {
            throw new InvalidArgumentException('workforce_capacity_owner_organization_mismatch');
        }
        $allowed = array_fill_keys(self::ALLOWED_FIELDS[$sourceType], true);

        return array_intersect_key($state, $allowed);
    }

    private function mutationId(string $sourceType, int $organizationId, ?array $oldState, ?array $newState): string
    {
        $identity = $newState['id'] ?? $newState['employee_id'] ?? $oldState['id'] ?? $oldState['employee_id'] ?? 0;
        $hash = hash('sha256', json_encode([
            'source_type' => $sourceType,
            'organization_id' => $organizationId,
            'old' => $this->canonical($oldState ?? []),
            'new' => $this->canonical($newState ?? []),
        ], JSON_THROW_ON_ERROR));

        return sprintf('%s:%d:%s', $sourceType, (int) $identity, $hash);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonical($nested);
            }
        }

        return $value;
    }
}
