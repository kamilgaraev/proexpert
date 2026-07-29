<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\HistoricalScheduleTaskState;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HistoricalScheduleTaskStateQuery
{
    public function latestForProjects(
        int $organizationId,
        array $projectIds,
        DateTimeImmutable $asOf,
    ): Collection {
        if ($organizationId < 1 || $projectIds === [] || ! array_is_list($projectIds)) {
            throw new InvalidArgumentException('historical_schedule_task_scope_invalid');
        }

        $ranked = DB::table('schedule_task_state_versions')
            ->select('*')
            ->selectRaw(
                'row_number() over (partition by organization_id, task_id order by effective_at desc, version desc) as state_rank'
            )
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('effective_at', '<=', $asOf);

        return DB::query()
            ->fromSub($ranked, 'ranked_schedule_task_states')
            ->where('state_rank', 1)
            ->orderBy('task_id')
            ->get()
            ->map(static fn (object $row): HistoricalScheduleTaskState => new HistoricalScheduleTaskState(
                taskId: (int) $row->task_id,
                projectId: (int) $row->project_id,
                scheduleId: (int) $row->schedule_id,
                effectiveAt: new DateTimeImmutable((string) $row->effective_at),
                plannedStart: new DateTimeImmutable((string) $row->planned_start),
                plannedEnd: new DateTimeImmutable((string) $row->planned_end),
                plannedDurationDays: (int) $row->planned_duration_days,
                status: (string) $row->status,
                taskType: (string) $row->task_type,
                wbsCode: $row->wbs_code === null ? null : (string) $row->wbs_code,
                ownerId: $row->owner_id === null ? null : (int) $row->owner_id,
                contractorId: $row->contractor_id === null ? null : (int) $row->contractor_id,
                sourceHash: (string) $row->source_hash,
                taskName: (string) $row->task_name,
                totalFloatDays: (int) $row->total_float_days,
                freeFloatDays: (int) $row->free_float_days,
                critical: (bool) $row->is_critical,
                active: (bool) $row->is_active,
                zoneId: $row->zone_id === null ? null : (int) $row->zone_id,
            ));
    }
}
