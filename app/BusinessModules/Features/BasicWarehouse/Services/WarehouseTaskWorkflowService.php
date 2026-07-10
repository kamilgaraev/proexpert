<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;

class WarehouseTaskWorkflowService
{
    public function canEdit(WarehouseTask $task): bool
    {
        return ! in_array($task->status, [
            WarehouseTask::STATUS_COMPLETED,
            WarehouseTask::STATUS_CANCELLED,
        ], true);
    }

    public function canDelete(WarehouseTask $task): bool
    {
        return in_array($task->status, [
            WarehouseTask::STATUS_DRAFT,
            WarehouseTask::STATUS_QUEUED,
            WarehouseTask::STATUS_CANCELLED,
        ], true);
    }

    public function canTransition(WarehouseTask $task, string $nextStatus): bool
    {
        if (in_array($task->status, [
            WarehouseTask::STATUS_COMPLETED,
            WarehouseTask::STATUS_CANCELLED,
        ], true) && $task->status === $nextStatus) {
            return false;
        }

        if ($task->status === WarehouseTask::STATUS_COMPLETED) {
            return false;
        }

        return $task->status === $nextStatus
            || in_array($nextStatus, $this->allowedTransitions($task), true);
    }

    public function allowedTransitions(WarehouseTask $task): array
    {
        return match ($task->status) {
            WarehouseTask::STATUS_DRAFT => [
                WarehouseTask::STATUS_QUEUED,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_QUEUED => [
                WarehouseTask::STATUS_IN_PROGRESS,
                WarehouseTask::STATUS_BLOCKED,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_IN_PROGRESS => [
                WarehouseTask::STATUS_BLOCKED,
                WarehouseTask::STATUS_COMPLETED,
                WarehouseTask::STATUS_CANCELLED,
                WarehouseTask::STATUS_QUEUED,
            ],
            WarehouseTask::STATUS_BLOCKED => [
                WarehouseTask::STATUS_QUEUED,
                WarehouseTask::STATUS_IN_PROGRESS,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_CANCELLED => [
                WarehouseTask::STATUS_QUEUED,
            ],
            default => [],
        };
    }

    public function resumeStatus(WarehouseTask $task): ?string
    {
        if ($task->status !== WarehouseTask::STATUS_BLOCKED) {
            return null;
        }

        return in_array($task->blocked_from_status, [
            WarehouseTask::STATUS_QUEUED,
            WarehouseTask::STATUS_IN_PROGRESS,
        ], true)
            ? $task->blocked_from_status
            : WarehouseTask::STATUS_QUEUED;
    }

    public function applyStatusTransition(
        WarehouseTask $task,
        string $nextStatus,
        ?int $userId,
        ?float $completedQuantity = null,
        ?string $notes = null,
        bool $validateTransition = true
    ): WarehouseTask {
        if ($validateTransition && ! $this->canTransition($task, $nextStatus)) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.task.status_invalid_transition'));
        }

        $currentStatus = $task->status;
        $updateData = ['status' => $nextStatus];

        if ($notes !== null) {
            $updateData['notes'] = $notes;
        }

        if ($nextStatus === WarehouseTask::STATUS_BLOCKED && $currentStatus !== WarehouseTask::STATUS_BLOCKED) {
            $updateData['blocked_from_status'] = $currentStatus;
        }

        if ($currentStatus === WarehouseTask::STATUS_BLOCKED && $nextStatus !== WarehouseTask::STATUS_BLOCKED) {
            $updateData['blocked_from_status'] = null;
        }

        if ($nextStatus === WarehouseTask::STATUS_IN_PROGRESS) {
            if ($task->started_at === null) {
                $updateData['started_at'] = now();
            }

            if ($task->assigned_to_id === null && $userId !== null) {
                $updateData['assigned_to_id'] = $userId;
            }
        }

        if ($nextStatus === WarehouseTask::STATUS_COMPLETED) {
            $updateData['started_at'] = $task->started_at ?? now();
            $updateData['completed_at'] = now();
            $updateData['completed_by_id'] = $userId;
            $updateData['completed_quantity'] = $completedQuantity
                ?? ($task->planned_quantity !== null ? (float) $task->planned_quantity : (float) ($task->completed_quantity ?? 0));
        }

        if ($completedQuantity !== null && $nextStatus !== WarehouseTask::STATUS_COMPLETED) {
            $updateData['completed_quantity'] = $completedQuantity;
        }

        $task->update($updateData);

        return $task->fresh();
    }
}
