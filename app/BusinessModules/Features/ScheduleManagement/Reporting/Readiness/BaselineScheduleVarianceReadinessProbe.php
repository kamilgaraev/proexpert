<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\ScheduleManagement\Models\ScheduleBaselineVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\ScheduleTaskStateVersion;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportSourceReadinessFactory;

final readonly class BaselineScheduleVarianceReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ReportSourceReadinessFactory $readiness,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'baseline_schedule_variance'
            && $definition->formulaVersion === 'schedule.baseline-variance.v1';
    }

    public function reportCodes(): array
    {
        return ['baseline_schedule_variance'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $tasks = ScheduleTask::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereHas('schedule', fn ($builder) => $builder
                ->whereIn('project_id', $context->scope->projectIds)
                ->where('is_template', false))
            ->orderBy('id')
            ->get(['id', 'schedule_id']);
        $states = ScheduleTaskStateVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('effective_at', '<=', $query->asOf)
            ->whereIn('task_id', $tasks->pluck('id'))
            ->orderBy('task_id')
            ->orderByDesc('effective_at')
            ->orderByDesc('version')
            ->get()
            ->unique('task_id')
            ->keyBy('task_id');
        $eligible = [];
        $projected = [];
        $gapCount = 0;
        foreach ($tasks as $task) {
            $eligible[] = [
                'kind' => 'schedule_task_state',
                'source_id' => (int) $task->id,
            ];
            $state = $states->get((int) $task->id);
            if ($state === null) {
                $gapCount++;

                continue;
            }
            $projected[] = [
                'kind' => 'schedule_task_state',
                'source_hash' => (string) $state->source_hash,
                'source_id' => (int) $task->id,
            ];
        }

        $schedules = ProjectSchedule::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('is_template', false)
            ->where('created_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get(['id']);
        $baselines = ScheduleBaselineVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('captured_at', '<=', $query->asOf)
            ->orderBy('schedule_id')
            ->orderByDesc('version')
            ->get()
            ->unique('schedule_id')
            ->keyBy('schedule_id');
        foreach ($schedules as $schedule) {
            $eligible[] = [
                'kind' => 'schedule_baseline',
                'schedule_id' => (int) $schedule->id,
            ];
            $baseline = $baselines->get((int) $schedule->id);
            if ($baseline === null) {
                $gapCount++;

                continue;
            }
            $projected[] = [
                'kind' => 'schedule_baseline',
                'schedule_id' => (int) $schedule->id,
                'source_hash' => (string) $baseline->source_hash,
            ];
        }

        $watermark = implode('.', [
            'schedule:'.(int) ($schedules->max('id') ?? 0),
            (int) ($states->max('id') ?? 0),
            (int) ($baselines->max('id') ?? 0),
        ]);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }
}
