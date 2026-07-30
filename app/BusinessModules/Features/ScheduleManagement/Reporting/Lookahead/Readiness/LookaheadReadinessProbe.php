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
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries\LookaheadResourceCandidateQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadResourceScope;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportScopedResourceFilter;
use App\Support\Reporting\ReportSourceReadinessFactory;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private LookaheadReadinessPolicyService $policies,
        private ReportSourceReadinessFactory $readiness,
        private HistoricalScheduleTaskStateQuery $historicalTasks,
        private ReportScopedResourceFilter $resourceFilter,
        private LookaheadResourceScope $resourceScope,
        private LookaheadResourceCandidateQuery $resourceCandidates,
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
        $scopedScheduleIds = $this->resourceFilter->ids(
            $context->scope,
            ['schedule'],
            $projectIds,
        );
        $scopedTaskIds = $this->resourceFilter->ids(
            $context->scope,
            ['task', 'schedule_task'],
            $projectIds,
        );
        $scopedConstraintIds = $this->resourceFilter->ids(
            $context->scope,
            ['constraint', 'work_constraint'],
            $projectIds,
        );
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
            ->when(
                $scopedScheduleIds !== null,
                static fn ($builder) => $builder->whereIn('id', $scopedScheduleIds),
            )
            ->pluck('id')
            ->map('intval')
            ->all();
        $constraintTaskIds = $this->resourceCandidates->taskIds(
            $context->scope,
            $projectIds,
            $scheduleIds,
            $query->asOf,
        );
        $resourceTaskIds = $this->intersectNullableIds($scopedTaskIds, $constraintTaskIds);
        $allStates = $projectIds === []
            ? collect()
            : $this->historicalTasks
                ->latestForProjects($context->scope->organizationId, $projectIds, $query->asOf)
                ->whereIn('scheduleId', $scheduleIds)
                ->when(
                    $resourceTaskIds !== null,
                    static fn ($items) => $items->whereIn('taskId', $resourceTaskIds),
                );
        $allTasks = ScheduleTask::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereIn('schedule_id', $scheduleIds)
            ->when(
                $resourceTaskIds !== null,
                static fn ($builder) => $builder->whereIn('id', $resourceTaskIds),
            )
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
            ->when(
                $scopedConstraintIds !== null,
                static fn ($builder) => $builder->whereIn('id', $scopedConstraintIds),
            )
            ->where('created_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get();
        $allowedConstraintsByTask = [];
        $missingConstraintsByTask = [];
        $projectedConstraints = [];
        $constraintEvents = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('constraint_id', $constraints->pluck('id')->all())
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('constraint_id')
            ->orderBy('event_version')
            ->get()
            ->groupBy('constraint_id');
        foreach ($constraints as $constraint) {
            $events = $constraintEvents->get((int) $constraint->id, collect());
            $latest = $events->last();
            if ($latest === null) {
                $linkedResource = $this->linkedResourceFromConstraint($constraint);
                if ($this->resourceScope->allowsConstraintIdentity(
                    $context->scope,
                    (int) $constraint->project_id,
                    (int) $constraint->schedule_id,
                    (int) $constraint->schedule_task_id,
                    (int) $constraint->id,
                    $linkedResource['type'] ?? null,
                    $linkedResource['id'] ?? null,
                )) {
                    $missingConstraintsByTask[(int) $constraint->schedule_task_id][] = (int) $constraint->id;
                }

                continue;
            }
            if (! $this->matchesConstraintFilters($query, $latest)) {
                continue;
            }
            $linkedResource = $this->linkedResource((array) $latest->evidence_refs);
            $state = new LookaheadConstraintState(
                constraintId: (int) $latest->constraint_id,
                type: (string) $latest->constraint_type,
                severity: (string) $latest->severity,
                status: (string) $latest->to_status,
                waiverUntil: $latest->waiver_until === null
                    ? null
                    : new DateTimeImmutable($latest->waiver_until->format(DATE_ATOM)),
                waiverEvidenceRef: $latest->waiver_evidence_ref,
                openedAt: new DateTimeImmutable($events->first()->occurred_at->format(DATE_ATOM)),
                linkedResourceType: $linkedResource['type'] ?? null,
                linkedResourceId: $linkedResource['id'] ?? null,
            );
            if (! $this->resourceScope->allowsConstraintIdentity(
                $context->scope,
                (int) $latest->project_id,
                (int) $latest->schedule_id,
                (int) $latest->task_id,
                (int) $latest->constraint_id,
                $state->linkedResourceType,
                $state->linkedResourceId,
            )) {
                continue;
            }
            $allowedConstraintsByTask[(int) $latest->task_id][] = $state;
            $projectedConstraints[(int) $constraint->id] = [
                'event_hashes' => $events->pluck('source_hash')->all(),
                'kind' => 'constraint',
                'source_id' => (int) $constraint->id,
            ];
        }

        $resourceScopedStates = collect();
        foreach ($selectedStates as $state) {
            $filteredConstraints = $this->resourceScope->filterConstraints(
                $context->scope,
                (int) $state->projectId,
                (int) $state->scheduleId,
                (int) $state->taskId,
                $allowedConstraintsByTask[(int) $state->taskId] ?? [],
            );
            if ($filteredConstraints === null
                && ($missingConstraintsByTask[(int) $state->taskId] ?? []) === []
            ) {
                continue;
            }
            $resourceScopedStates->push($state);
        }
        $selectedStates = $resourceScopedStates;
        $selectedTaskIds = $selectedStates->pluck('taskId')->map('intval')->all();

        foreach ($constraints as $constraint) {
            $taskId = (int) $constraint->schedule_task_id;
            if (! in_array($taskId, $selectedTaskIds, true)) {
                continue;
            }
            if (isset($projectedConstraints[(int) $constraint->id])) {
                $eligible[] = [
                    'created_at' => $constraint->created_at?->format(DATE_ATOM),
                    'kind' => 'constraint',
                    'source_id' => (int) $constraint->id,
                ];
                $projected[] = $projectedConstraints[(int) $constraint->id];

                continue;
            }
            if (in_array(
                (int) $constraint->id,
                $missingConstraintsByTask[$taskId] ?? [],
                true,
            )) {
                $eligible[] = [
                    'created_at' => $constraint->created_at?->format(DATE_ATOM),
                    'kind' => 'constraint',
                    'source_id' => (int) $constraint->id,
                ];
                $gapCount++;
            }
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

    private function intersectNullableIds(?array $left, ?array $right): ?array
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return array_values(array_intersect($left, $right));
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

    private function linkedResource(array $evidenceRefs): ?array
    {
        foreach ($evidenceRefs as $reference) {
            if (! is_array($reference)
                || ($reference['type'] ?? null) === 'waiver_evidence'
                || ! is_string($reference['type'] ?? null)
                || ! is_numeric($reference['id'] ?? null)
                || (int) $reference['id'] < 1
            ) {
                continue;
            }

            return [
                'type' => $reference['type'],
                'id' => (int) $reference['id'],
            ];
        }

        return null;
    }

    private function linkedResourceFromConstraint(WorkConstraint $constraint): ?array
    {
        $metadata = (array) $constraint->metadata;
        $linked = $metadata['linked_action'] ?? $metadata['linked_entity'] ?? null;
        if (! is_array($linked)
            || ! is_string($linked['type'] ?? null)
            || ! is_numeric($linked['id'] ?? null)
            || (int) $linked['id'] < 1
        ) {
            return null;
        }

        return [
            'type' => $linked['type'],
            'id' => (int) $linked['id'],
        ];
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
