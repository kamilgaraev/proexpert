<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyService;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportSourceReadinessFactory;
use InvalidArgumentException;

final readonly class LookaheadReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private LookaheadReadinessPolicyService $policies,
        private ReportSourceReadinessFactory $readiness,
        private HistoricalScheduleTaskStateQuery $historicalTasks,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'lookahead_readiness'
            && $definition->formulaVersion === 'lookahead_readiness.v1';
    }

    public function reportCodes(): array
    {
        return ['lookahead_readiness'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $eligible = [];
        $projected = [];
        $gapCount = 0;
        $projectIds = array_values(array_intersect(
            $context->scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?: $context->scope->projectIds,
        ));
        if ($projectIds === []) {
            throw new InvalidArgumentException('lookahead_project_filter_empty');
        }
        try {
            $policySet = $this->policies->activeForProjects(
                $context->scope->organizationId,
                $projectIds,
                $query->asOf,
            );
            foreach ($projectIds as $projectId) {
                $policy = $policySet->forProject($projectId);
                $eligible[] = ['kind' => 'policy', 'project_id' => $projectId];
                $projected[] = [
                    'kind' => 'policy',
                    'project_id' => $projectId,
                    'source_hash' => $policy->sourceHash,
                ];
            }
        } catch (InvalidArgumentException) {
            foreach ($projectIds as $projectId) {
                $eligible[] = ['kind' => 'policy', 'project_id' => $projectId];
                $gapCount++;
            }
        }

        $scheduleIds = ProjectSchedule::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('created_at', '<=', $query->asOf)
            ->where('is_template', false)
            ->pluck('id')
            ->map('intval')
            ->all();
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
            ->get(['id']);
        $allStatesByTask = $allStates->keyBy('taskId');
        $selectedStates = $allStates
            ->filter(fn ($state): bool => $state->active && $this->matchesTaskFilters($query, $state));
        $selectedTaskIds = $selectedStates->pluck('taskId')->map('intval')->all();
        $missingTaskIds = $allTasks
            ->reject(fn (ScheduleTask $task): bool => $allStatesByTask->has((int) $task->id))
            ->pluck('id')
            ->map('intval')
            ->all();

        $constraints = WorkConstraint::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('schedule_task_id', $selectedTaskIds)
            ->where('created_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get();
        foreach ($constraints as $constraint) {
            $events = WorkConstraintTransitionEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('constraint_id', $constraint->id)
                ->where('occurred_at', '<=', $query->asOf)
                ->orderBy('event_version')
                ->get();
            $latest = $events->last();
            if ($latest !== null && ! $this->matchesConstraintFilters($query, $latest)) {
                continue;
            }
            $eligible[] = [
                'created_at' => $constraint->created_at?->format(DATE_ATOM),
                'kind' => 'constraint',
                'source_id' => (int) $constraint->id,
            ];
            if ($events->isEmpty()) {
                $gapCount++;

                continue;
            }
            $projected[] = [
                'event_hashes' => $events->pluck('source_hash')->all(),
                'kind' => 'constraint',
                'source_id' => (int) $constraint->id,
            ];
        }

        foreach (array_values(array_unique([...$selectedTaskIds, ...$missingTaskIds])) as $taskId) {
            $eligible[] = ['kind' => 'schedule_task_state', 'source_id' => $taskId];
            $state = $allStatesByTask->get($taskId);
            if ($state === null) {
                $gapCount++;

                continue;
            }
            $projected[] = [
                'kind' => 'schedule_task_state',
                'source_hash' => $state->sourceHash,
                'source_id' => $taskId,
            ];
        }

        $watermark = implode('.', [
            'lookahead:'.(int) ($constraints->max('id') ?? 0),
            (int) (WorkConstraintTransitionEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->whereIn('project_id', $projectIds)
                ->where('occurred_at', '<=', $query->asOf)
                ->max('id') ?? 0),
            (int) ($allStates->max('taskId') ?? 0),
        ]);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }

    private function matchesTaskFilters(ReportQuery $query, object $state): bool
    {
        $values = $query->filters->values;
        $horizonDays = $values['horizon_days'] ?? null;
        if ($horizonDays !== null) {
            if (! is_numeric($horizonDays) || (int) $horizonDays < 1) {
                throw new InvalidArgumentException('lookahead_horizon_filter_invalid');
            }
            $horizonEnd = $query->asOf->modify('+'.(int) $horizonDays.' days');
            if ($state->plannedStart < $query->asOf || $state->plannedStart > $horizonEnd) {
                return false;
            }
        }

        return $this->matches($values['zone_ids'] ?? [], $state->zoneId)
            && $this->matches($values['wbs_ids'] ?? [], $state->wbsCode)
            && $this->matches($values['owner_ids'] ?? [], $state->ownerId)
            && $this->matches($values['contractor_ids'] ?? [], $state->contractorId)
            && $this->matches($values['task_statuses'] ?? [], $state->status);
    }

    private function matchesConstraintFilters(ReportQuery $query, object $event): bool
    {
        $values = $query->filters->values;

        return $this->matches(
            $values['constraint_types'] ?? $values['types'] ?? [],
            $event->constraint_type,
        )
            && $this->matches($values['severities'] ?? [], $event->severity)
            && $this->matches($values['statuses'] ?? [], $event->to_status);
    }

    private function positiveIntegerFilter(ReportQuery $query, string $key): array
    {
        $values = $query->filters->values[$key] ?? [];
        if ($values === []) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('lookahead_filter_invalid');
        }

        $result = array_map('intval', $values);
        if (array_filter($result, static fn (int $value): bool => $value < 1) !== []) {
            throw new InvalidArgumentException('lookahead_filter_invalid');
        }

        return array_values(array_unique($result));
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
