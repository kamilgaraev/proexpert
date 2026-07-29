<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\ScheduleTaskStateVersion;
use App\Models\ScheduleTask;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ScheduleTaskStateRecorder
{
    public function capture(
        ScheduleTask $task,
        DateTimeImmutable $effectiveAt,
        string $sourceKind,
        bool $active = true,
    ): ScheduleTaskStateVersion {
        $schedule = $task->relationLoaded('schedule')
            ? $task->schedule
            : $task->schedule()->first();
        if ($schedule === null
            || (int) $task->id < 1
            || (int) $task->organization_id < 1
            || (int) $schedule->project_id < 1
            || trim($sourceKind) === ''
            || $task->planned_start_date === null
            || $task->planned_end_date === null
        ) {
            throw new InvalidArgumentException('schedule_task_state_capture_invalid');
        }

        $customFields = is_array($task->custom_fields) ? $task->custom_fields : [];
        $payload = [
            'contractor_id' => $this->positiveIntegerOrNull($customFields['contractor_id'] ?? null),
            'free_float_days' => (int) ($task->free_float_days ?? 0),
            'is_critical' => (bool) $task->is_critical,
            'is_active' => $active,
            'organization_id' => (int) $task->organization_id,
            'owner_id' => $task->assigned_user_id === null ? null : (int) $task->assigned_user_id,
            'planned_duration_days' => max(1, (int) $task->planned_duration_days),
            'planned_end' => $task->planned_end_date->format('Y-m-d'),
            'planned_start' => $task->planned_start_date->format('Y-m-d'),
            'project_id' => (int) $schedule->project_id,
            'schedule_id' => (int) $task->schedule_id,
            'source_kind' => $sourceKind,
            'status' => $task->status instanceof \BackedEnum
                ? (string) $task->status->value
                : (string) $task->status,
            'task_id' => (int) $task->id,
            'task_name' => (string) $task->name,
            'task_type' => $task->task_type instanceof \BackedEnum
                ? (string) $task->task_type->value
                : (string) $task->task_type,
            'total_float_days' => (int) ($task->total_float_days ?? 0),
            'wbs_code' => $task->wbs_code,
            'zone_id' => $this->positiveIntegerOrNull($customFields['zone_id'] ?? null),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($payload));

        return DB::transaction(function () use ($task, $effectiveAt, $payload, $sourceHash): ScheduleTaskStateVersion {
            $latest = ScheduleTaskStateVersion::query()
                ->where('organization_id', $task->organization_id)
                ->where('task_id', $task->id)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();
            if ($latest !== null && hash_equals((string) $latest->source_hash, $sourceHash)) {
                return $latest;
            }

            return ScheduleTaskStateVersion::query()->create($payload + [
                'effective_at' => $effectiveAt,
                'source_hash' => $sourceHash,
                'version' => $latest === null ? 1 : (int) $latest->version + 1,
            ]);
        });
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
