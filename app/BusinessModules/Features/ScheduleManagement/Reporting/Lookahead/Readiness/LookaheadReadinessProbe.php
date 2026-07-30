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
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadConstraintHistoryStream;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadResourceScope;
use App\Models\ScheduleTask;
use App\Support\Reporting\DeterministicReadinessAccumulator;
use App\Support\Reporting\ReportScopedResourceFilter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LookaheadReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private LookaheadReadinessPolicyService $policies,
        private HistoricalScheduleTaskStateQuery $historicalTasks,
        private ReportScopedResourceFilter $resourceFilter,
        private LookaheadResourceScope $resourceScope,
        private LookaheadResourceCandidateQuery $resourceCandidates,
        private LookaheadConstraintHistoryStream $constraintHistory,
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
        if (DB::transactionLevel() > 0) {
            return $this->inspectWithinSnapshot($context, $query);
        }

        return DB::transaction(function () use ($context, $query): ReportSourceReadiness {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->inspectWithinSnapshot($context, $query);
        }, 3);
    }

    private function inspectWithinSnapshot(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $measurement = new DeterministicReadinessAccumulator;
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
        $constraintTaskIds = $this->resourceCandidates->taskIds(
            $context->scope,
            $projectIds,
            $scopedScheduleIds,
            $query->asOf,
        );
        $resourceTaskIds = $this->intersectNullableIds($scopedTaskIds, $constraintTaskIds);
        $allStates = $projectIds === []
            ? collect()
            : $this->historicalTasks
                ->latestForLookaheadCursor(
                    $context->scope->organizationId,
                    $projectIds,
                    $query->asOf,
                    $scopedScheduleIds,
                    $resourceTaskIds,
                );
        $selectedStates = $allStates
            ->filter(fn ($state): bool => $state->active && $this->matchesTaskFilters($query, $state));
        if (! $this->hasTaskFilters($query)) {
            $missingTasks = ScheduleTask::withTrashed()
                ->where('organization_id', $context->scope->organizationId)
                ->where('created_at', '<=', $query->asOf)
                ->whereHas('schedule', static fn ($builder) => $builder
                    ->whereIn('project_id', $projectIds)
                    ->where('created_at', '<=', $query->asOf)
                    ->where('is_template', false)
                    ->when(
                        $scopedScheduleIds !== null,
                        static fn ($queryBuilder) => $queryBuilder->whereIn('id', $scopedScheduleIds),
                    ))
                ->when(
                    $resourceTaskIds !== null,
                    static fn ($builder) => $builder->whereIn('id', $resourceTaskIds),
                )
                ->whereNotExists(static function ($builder) use ($context, $query): void {
                    $builder
                        ->selectRaw('1')
                        ->from('schedule_task_state_versions as state_coverage')
                        ->whereColumn('state_coverage.task_id', 'schedule_tasks.id')
                        ->where('state_coverage.organization_id', $context->scope->organizationId)
                        ->where('state_coverage.effective_at', '<=', $query->asOf);
                });
            foreach ($missingTasks->lazyById(500) as $missingTask) {
                $measurement->eligible([
                    'kind' => 'schedule_task_state',
                    'source_id' => (int) $missingTask->id,
                ]);
                $gapCount++;
            }
        }

        $effectiveProjectIdMap = [];
        $maxConstraintId = 0;
        $maxTaskId = 0;
        foreach ($selectedStates->chunk(100) as $statePage) {
            $taskIds = [];
            foreach ($statePage as $state) {
                $taskIds[] = (int) $state->taskId;
                $maxTaskId = max($maxTaskId, (int) $state->taskId);
            }
            $constraintsByTask = [];
            $historyIds = [];
            foreach ($this->constraintHistory->states(
                $context->scope,
                $taskIds,
                $scopedConstraintIds,
                $query->asOf,
            ) as $entry) {
                $constraintState = $entry['state'];
                $constraintsByTask[(int) $entry['task_id']][] = $constraintState;
                $historyIds[$constraintState->constraintId] = true;
            }
            $constraintRecords = [];
            $missingByTask = [];
            $records = WorkConstraint::withTrashed()
                ->where('organization_id', $context->scope->organizationId)
                ->whereIn('schedule_task_id', $taskIds)
                ->when(
                    $scopedConstraintIds !== null,
                    static fn ($builder) => $builder->whereIn('id', $scopedConstraintIds),
                )
                ->where('created_at', '<=', $query->asOf)
                ->orderBy('id')
                ->cursor();
            foreach ($records as $constraint) {
                $constraintId = (int) $constraint->id;
                $maxConstraintId = max($maxConstraintId, $constraintId);
                $constraintRecords[$constraintId] = $constraint;
                if (isset($historyIds[$constraintId])) {
                    continue;
                }
                $linked = $this->linkedResourceFromConstraint($constraint);
                if ($this->resourceScope->allowsConstraintIdentity(
                    $context->scope,
                    (int) $constraint->project_id,
                    (int) $constraint->schedule_id,
                    (int) $constraint->schedule_task_id,
                    $constraintId,
                    $linked['type'] ?? null,
                    $linked['id'] ?? null,
                )) {
                    $missingByTask[(int) $constraint->schedule_task_id][] = $constraintId;
                }
            }
            foreach ($statePage as $state) {
                $filtered = $this->resourceScope->filterConstraints(
                    $context->scope,
                    (int) $state->projectId,
                    (int) $state->scheduleId,
                    (int) $state->taskId,
                    $constraintsByTask[(int) $state->taskId] ?? [],
                );
                if ($filtered !== null) {
                    $filtered = $this->filterConstraints($query, $filtered);
                }
                $missingIds = $missingByTask[(int) $state->taskId] ?? [];
                if ($filtered === null && $missingIds === []) {
                    continue;
                }
                $effectiveProjectIdMap[(int) $state->projectId] = true;
                $measurement->eligible([
                    'kind' => 'schedule_task_state',
                    'source_id' => (int) $state->taskId,
                ]);
                $measurement->projected([
                    'kind' => 'schedule_task_state',
                    'source_hash' => $state->sourceHash,
                    'source_id' => (int) $state->taskId,
                ]);
                foreach ($filtered ?? [] as $constraintState) {
                    $record = $constraintRecords[$constraintState->constraintId] ?? null;
                    $measurement->eligible([
                        'created_at' => $record?->created_at?->format(DATE_ATOM),
                        'kind' => 'constraint',
                        'source_id' => $constraintState->constraintId,
                    ]);
                    $measurement->projected([
                        'transition_lineage' => $constraintState->transitionLineage,
                        'kind' => 'constraint',
                        'source_id' => $constraintState->constraintId,
                    ]);
                }
                foreach ($missingIds as $constraintId) {
                    $record = $constraintRecords[$constraintId] ?? null;
                    $measurement->eligible([
                        'created_at' => $record?->created_at?->format(DATE_ATOM),
                        'kind' => 'constraint',
                        'source_id' => $constraintId,
                    ]);
                    $gapCount++;
                }
            }
        }
        $effectiveProjectIds = array_keys($effectiveProjectIdMap);
        sort($effectiveProjectIds, SORT_NUMERIC);
        if ($effectiveProjectIds !== []) {
            try {
                $policySet = $this->policies->activeForProjects(
                    $context->scope->organizationId,
                    $effectiveProjectIds,
                    $query->asOf,
                );
                foreach ($effectiveProjectIds as $projectId) {
                    $policy = $policySet->forProject($projectId);
                    $measurement->eligible(['kind' => 'policy', 'project_id' => $projectId]);
                    $measurement->projected([
                        'kind' => 'policy',
                        'project_id' => $projectId,
                        'source_hash' => $policy->sourceHash,
                    ]);
                }
            } catch (InvalidArgumentException) {
                foreach ($effectiveProjectIds as $projectId) {
                    $measurement->eligible(['kind' => 'policy', 'project_id' => $projectId]);
                    $gapCount++;
                }
            }
        }

        $watermark = implode('.', [
            'lookahead:'.$maxConstraintId,
            (int) (WorkConstraintTransitionEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->whereIn('project_id', $projectIds)
                ->where('occurred_at', '<=', $query->asOf)
                ->max('id') ?? 0),
            $maxTaskId,
        ]);

        return $measurement->finish($gapCount, 0, $watermark);
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

    private function hasTaskFilters(ReportQuery $query): bool
    {
        $values = $query->filters->values;

        return ($values['horizon_days'] ?? null) !== null
            || ($values['zone_ids'] ?? []) !== []
            || ($values['wbs_ids'] ?? []) !== []
            || ($values['owner_ids'] ?? []) !== []
            || ($values['contractor_ids'] ?? []) !== []
            || ($values['task_statuses'] ?? []) !== [];
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

    private function filterConstraints(ReportQuery $query, array $constraints): ?array
    {
        $filtered = array_values(array_filter(
            $constraints,
            fn (LookaheadConstraintState $constraint): bool => $this->matchesConstraintFilters($query, (object) [
                'constraint_type' => $constraint->type,
                'severity' => $constraint->severity,
                'to_status' => $constraint->status,
            ]),
        ));

        return $filtered === [] && $this->hasConstraintFilters($query) ? null : $filtered;
    }

    private function hasConstraintFilters(ReportQuery $query): bool
    {
        $values = $query->filters->values;

        return ($values['constraint_types'] ?? $values['types'] ?? []) !== []
            || ($values['severities'] ?? []) !== []
            || ($values['statuses'] ?? []) !== [];
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
