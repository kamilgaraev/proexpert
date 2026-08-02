<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentScheduleRevisionSourceGuard
{
    public function __construct(
        private ScheduleSourceWatermark $watermark,
        private ScheduleRevisionSourceMatcher $matcher = new ScheduleRevisionSourceMatcher,
    ) {}

    public function assertCurrent(ScheduleRevisionDraft $draft): void
    {
        $source = $this->source(
            $draft->organizationId,
            $draft->projectId,
            $draft->scheduleId,
        );
        $current = $this->watermark->make($source['schedule'], $source['tasks'], $source['dependencies']);
        if (! hash_equals($current, $draft->sourceWatermark)) {
            throw new LogicException('lookahead_readiness_stale_schedule_source');
        }
        $this->matcher->assertMatches($draft, $source['schedule'], $source['tasks'], $source['dependencies']);
    }

    public function current(int $organizationId, int $projectId, int $scheduleId): string
    {
        $source = $this->source($organizationId, $projectId, $scheduleId);

        return $this->watermark->make($source['schedule'], $source['tasks'], $source['dependencies']);
    }

    private function source(int $organizationId, int $projectId, int $scheduleId): array
    {
        if (DB::transactionLevel() > 0 && DB::getDriverName() === 'pgsql') {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["lookahead-schedule-source:{$scheduleId}"],
            );
        }
        $scheduleQuery = DB::table('project_schedules')
            ->where('id', $scheduleId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId);
        $taskQuery = DB::table('schedule_tasks')
            ->where('schedule_id', $scheduleId)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id');
        $dependencyQuery = DB::table('task_dependencies')
            ->where('schedule_id', $scheduleId)
            ->where('organization_id', $organizationId)
            ->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $scheduleQuery->lockForUpdate();
            $taskQuery->lockForUpdate();
            $dependencyQuery->lockForUpdate();
        }

        $schedule = $scheduleQuery->first([
            'id',
            'organization_id',
            'project_id',
            'status',
            'timezone',
            'updated_at',
        ]);
        if ($schedule === null) {
            throw new LogicException('lookahead_readiness_schedule_lineage_invalid');
        }
        $tasks = $taskQuery->get([
            'id',
            'parent_task_id',
            'name',
            'wbs_code',
            'task_type',
            'planned_start_date',
            'planned_end_date',
            'planned_duration_days',
            'planned_work_hours',
            'quantity',
            'is_critical',
            'constraint_type',
            'constraint_date',
            'updated_at',
        ])->map(static fn (object $row): array => (array) $row)->all();
        $dependencies = $dependencyQuery->get([
            'id',
            'predecessor_task_id',
            'successor_task_id',
            'dependency_type',
            'lag_days',
            'lag_hours',
            'lag_type',
            'is_active',
            'validation_status',
            'updated_at',
        ])->map(static fn (object $row): array => (array) $row)->all();

        return [
            'schedule' => (array) $schedule,
            'tasks' => $tasks,
            'dependencies' => $dependencies,
        ];
    }
}
