<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\ScheduleManagement\Models\ScheduleBaselineVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateQuery;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportSourceReadinessFactory;

final readonly class BaselineScheduleVarianceReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ReportSourceReadinessFactory $readiness,
        private HistoricalScheduleTaskStateQuery $historicalTasks,
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
        $projectIds = array_values(array_intersect(
            $context->scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?: $context->scope->projectIds,
        ));
        $scheduleFilter = $this->positiveIntegerFilter($query, 'schedule_ids');
        $candidateSchedules = ProjectSchedule::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereIn('project_id', $projectIds)
            ->where('is_template', false)
            ->when($scheduleFilter !== [], fn ($builder) => $builder->whereIn('id', $scheduleFilter))
            ->orderBy('id')
            ->get(['id']);
        $scheduleIds = $candidateSchedules->pluck('id')->map('intval')->all();
        $allStates = $projectIds === []
            ? collect()
            : $this->historicalTasks
                ->latestForProjects($context->scope->organizationId, $projectIds, $query->asOf)
                ->whereIn('scheduleId', $scheduleIds);
        $allTasks = ScheduleTask::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereIn('schedule_id', $scheduleIds)
            ->orderBy('id')
            ->get(['id', 'schedule_id']);
        $allStatesByTask = $allStates->keyBy('taskId');
        $selectedStates = $allStates
            ->filter(fn ($state): bool => $this->matchesTaskFilters($query, $state))
            ->values();
        $selectedStateIds = $selectedStates
            ->pluck('taskId')
            ->map('intval')
            ->all();
        $tasks = $allTasks->filter(
            fn (ScheduleTask $task): bool => ! $allStatesByTask->has((int) $task->id)
                || in_array((int) $task->id, $selectedStateIds, true),
        );
        $states = $allStatesByTask;
        $selectedScheduleIds = $selectedStates->pluck('scheduleId')->map('intval')->unique()->values()->all();
        $schedules = $candidateSchedules->whereIn('id', $selectedScheduleIds)->values();
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
                'source_hash' => $state->sourceHash,
                'source_id' => (int) $task->id,
            ];
        }

        $baselines = ScheduleBaselineVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->whereIn('schedule_id', $selectedScheduleIds)
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

    private function matchesTaskFilters(ReportQuery $query, object $state): bool
    {
        $values = $query->filters->values;

        return $this->matches($values['task_ids'] ?? [], $state->taskId)
            && $this->matches($values['wbs_ids'] ?? [], $state->wbsCode)
            && $this->matches($values['owner_ids'] ?? [], $state->ownerId)
            && $this->matches($values['contractor_ids'] ?? [], $state->contractorId)
            && $this->matches($values['statuses'] ?? [], $state->status)
            && (! array_key_exists('critical', $values) || (bool) $values['critical'] === $state->critical);
    }

    private function positiveIntegerFilter(ReportQuery $query, string $key): array
    {
        $values = $query->filters->values[$key] ?? [];
        if ($values === []) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $value): bool => $value > 0,
        )));
    }

    private function matches(mixed $filter, int|string|null $value): bool
    {
        if ($filter === []) {
            return true;
        }

        return is_array($filter)
            && array_is_list($filter)
            && $value !== null
            && in_array((string) $value, array_map('strval', $filter), true);
    }
}
