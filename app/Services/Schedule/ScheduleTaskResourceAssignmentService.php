<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Material;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskResource;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class ScheduleTaskResourceAssignmentService
{
    public function assign(ProjectSchedule $schedule, ScheduleTask $task, array $data, User $assignedBy): TaskResource
    {
        return DB::transaction(function () use ($schedule, $task, $data, $assignedBy): TaskResource {
            $plannedCost = $data['estimated_cost'] ?? null;
            if ($data['resource_type'] === 'material' && $plannedCost === null && isset($data['cost_per_unit'])) {
                $quantity = BigDecimal::of((string) ($data['allocated_units'] ?? 1))->toScale(2, RoundingMode::HalfUp);
                $unitCost = BigDecimal::of((string) $data['cost_per_unit'])->toScale(2, RoundingMode::HalfUp);
                $plannedCost = (string) $quantity->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp);
            }
            $resource = TaskResource::query()->create(array_merge(
                [
                    'task_id' => $task->id,
                    'schedule_id' => $schedule->id,
                    'organization_id' => $schedule->organization_id,
                    'assigned_by_user_id' => $assignedBy->id,
                    'allocation_percent' => $data['allocation_percent'],
                    'assignment_start_date' => $data['assignment_start_date'],
                    'assignment_end_date' => $data['assignment_end_date'],
                    'total_planned_cost' => $plannedCost,
                    'allocated_units' => $data['allocated_units'] ?? 1,
                    'allocated_hours' => $data['allocated_hours'] ?? null,
                    'cost_per_hour' => $data['cost_per_hour'] ?? null,
                    'cost_per_unit' => $data['cost_per_unit'] ?? null,
                    'role' => $data['role'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'assignment_status' => 'planned',
                ],
                $this->resourceAttributes($data)
            ));

            $resource->checkConflicts();

            return $resource->fresh(['user', 'material.measurementUnit']);
        });
    }

    public function remove(ProjectSchedule $schedule, ScheduleTask $task, int $resourceId): ?TaskResource
    {
        $resource = TaskResource::query()
            ->where('id', $resourceId)
            ->where('schedule_id', $schedule->id)
            ->where('task_id', $task->id)
            ->first();

        if (!$resource) {
            return null;
        }

        $resource->delete();

        return $resource;
    }

    public function toResponse(TaskResource $resource): array
    {
        return [
            'id' => $resource->id,
            'task_id' => $resource->task_id,
            'schedule_id' => $resource->schedule_id,
            'resource_type' => $resource->resource_type,
            'resource_id' => $resource->resource_id,
            'user_id' => $resource->user_id,
            'material_id' => $resource->material_id,
            'equipment_name' => $resource->equipment_name,
            'external_resource_name' => $resource->external_resource_name,
            'allocation_percent' => (float) $resource->allocation_percent,
            'assignment_start_date' => $resource->assignment_start_date?->format('Y-m-d'),
            'assignment_end_date' => $resource->assignment_end_date?->format('Y-m-d'),
            'allocated_units' => $resource->allocated_units !== null ? (float) $resource->allocated_units : null,
            'cost_per_unit' => $resource->cost_per_unit !== null ? (float) $resource->cost_per_unit : null,
            'estimated_cost' => $resource->total_planned_cost !== null ? (float) $resource->total_planned_cost : null,
            'total_planned_cost' => $resource->total_planned_cost !== null ? (float) $resource->total_planned_cost : null,
            'has_conflicts' => (bool) $resource->has_conflicts,
            'conflict_details' => $resource->conflict_details ?? [],
            'user' => $resource->user ? [
                'id' => $resource->user->id,
                'name' => $resource->user->name,
            ] : null,
            'material' => $resource->material ? [
                'id' => $resource->material->id,
                'name' => $resource->material->name,
                'unit_name' => $resource->material->measurementUnit?->short_name
                    ?? $resource->material->measurementUnit?->name,
            ] : null,
            'created_at' => $resource->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $resource->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resourceAttributes(array $data): array
    {
        return match ($data['resource_type']) {
            'user' => [
                'resource_type' => 'user',
                'resource_id' => (int) $data['user_id'],
                'resource_model' => User::class,
                'user_id' => (int) $data['user_id'],
            ],
            'material' => [
                'resource_type' => 'material',
                'resource_id' => (int) $data['material_id'],
                'resource_model' => Material::class,
                'material_id' => (int) $data['material_id'],
            ],
            'equipment' => [
                'resource_type' => 'equipment',
                'resource_id' => 0,
                'resource_model' => 'equipment',
                'equipment_name' => $data['equipment_name'],
            ],
            'external_resource' => [
                'resource_type' => 'external_resource',
                'resource_id' => 0,
                'resource_model' => 'external_resource',
                'external_resource_name' => $data['external_resource_name'],
            ],
        };
    }
}
