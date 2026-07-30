<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries\LookaheadResourceCandidateQuery;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportScopedResourceFilter;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class LookaheadReadinessSnapshotMaterializer
{
    private const FORMULA_VERSION = 'lookahead_readiness.v1';

    public function __construct(
        private LookaheadReadinessPolicyService $policies,
        private LookaheadReadinessFormula $formula,
        private HistoricalScheduleTaskStateQuery $historicalTasks,
        private LookaheadResourceScope $resourceScope,
        private LookaheadResourceCandidateQuery $resourceCandidates,
    ) {}

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('lookahead_materialization_identity_invalid');
        }
        $projectIds = array_values(array_intersect(
            $scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?: $scope->projectIds,
        ));
        if ($projectIds === []) {
            throw new InvalidArgumentException('lookahead_project_filter_empty');
        }
        $resourceFilter = new ReportScopedResourceFilter;
        $scopedScheduleIds = $resourceFilter->ids(
            $scope,
            ['schedule'],
            $projectIds,
        );
        $scopedTaskIds = $resourceFilter->ids(
            $scope,
            ['task', 'schedule_task'],
            $projectIds,
        );
        $scopedConstraintIds = $resourceFilter->ids(
            $scope,
            ['constraint', 'work_constraint'],
            $projectIds,
        );
        $scheduleIds = \App\Models\ProjectSchedule::query()
            ->where('organization_id', $scope->organizationId)
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
            $scope,
            $projectIds,
            $scheduleIds,
            $query->asOf,
        );
        $resourceTaskIds = $this->intersectNullableIds($scopedTaskIds, $constraintTaskIds);
        $states = $this->historicalTasks
            ->latestForProjects($scope->organizationId, $projectIds, $query->asOf)
            ->whereIn('scheduleId', $scheduleIds)
            ->when(
                $resourceTaskIds !== null,
                static fn ($items) => $items->whereIn('taskId', $resourceTaskIds),
            )
            ->values();
        $eligibleTaskIds = ScheduleTask::withTrashed()
            ->where('organization_id', $scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereIn('schedule_id', $scheduleIds)
            ->when(
                $resourceTaskIds !== null,
                static fn ($builder) => $builder->whereIn('id', $resourceTaskIds),
            )
            ->pluck('id')
            ->map('intval')
            ->all();
        $versionedTaskIds = $states
            ->pluck('taskId')
            ->map('intval')
            ->all();
        if (array_diff($eligibleTaskIds, $versionedTaskIds) !== []) {
            throw new InvalidArgumentException('historical_schedule_task_state_incomplete');
        }

        $states = $states
            ->filter(fn ($state): bool => $state->active && $this->matchesTaskFilters($query, $state))
            ->values();
        $taskIds = $states->pluck('taskId')->all();
        $events = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('task_id', $taskIds)
            ->when(
                $scopedConstraintIds !== null,
                static fn ($builder) => $builder->whereIn('constraint_id', $scopedConstraintIds),
            )
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('constraint_id')
            ->orderBy('event_version')
            ->get()
            ->groupBy('task_id')
            ->map(static function ($taskEvents): array {
                return $taskEvents
                    ->groupBy('constraint_id')
                    ->map(static function ($constraintEvents) {
                        $latest = $constraintEvents->last();
                        $latest->setAttribute('opened_at_source', $constraintEvents->first()->occurred_at);
                        $latest->setAttribute('transition_lineage', $constraintEvents
                            ->map(static fn (WorkConstraintTransitionEvent $event): array => [
                                'id' => (int) $event->id,
                                'version' => (int) $event->event_version,
                                'source_hash' => (string) $event->source_hash,
                            ])
                            ->values()
                            ->all());

                        return $latest;
                    })
                    ->values()
                    ->all();
            });
        $capturedConstraintIds = $events
            ->flatten(1)
            ->pluck('constraint_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $uncapturedConstraints = WorkConstraint::withTrashed()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('schedule_task_id', $taskIds)
            ->when(
                $scopedConstraintIds !== null,
                static fn ($builder) => $builder->whereIn('id', $scopedConstraintIds),
            )
            ->where('created_at', '<=', $query->asOf)
            ->when(
                $capturedConstraintIds !== [],
                static fn ($builder) => $builder->whereNotIn('id', $capturedConstraintIds),
            )
            ->get();
        $uncapturedConstraintExists = $uncapturedConstraints->contains(
            function (WorkConstraint $constraint) use ($scope): bool {
                $linkedResource = $this->linkedResourceFromConstraint($constraint);

                return $this->resourceScope->allowsConstraintIdentity(
                    $scope,
                    (int) $constraint->project_id,
                    (int) $constraint->schedule_id,
                    (int) $constraint->schedule_task_id,
                    (int) $constraint->id,
                    $linkedResource['type'] ?? null,
                    $linkedResource['id'] ?? null,
                );
            },
        );
        if ($uncapturedConstraintExists) {
            throw new InvalidArgumentException('lookahead_constraint_history_incomplete');
        }
        $inputs = [];
        foreach ($states as $state) {
            $constraints = [];
            foreach ($events->get($state->taskId, []) as $event) {
                $linkedResource = $this->linkedResource((array) $event->evidence_refs);
                $constraints[] = new LookaheadConstraintState(
                    constraintId: (int) $event->constraint_id,
                    type: (string) $event->constraint_type,
                    severity: (string) $event->severity,
                    status: (string) $event->to_status,
                    waiverUntil: $event->waiver_until === null
                        ? null
                        : new DateTimeImmutable($event->waiver_until->format(DATE_ATOM)),
                    waiverEvidenceRef: $event->waiver_evidence_ref,
                    openedAt: new DateTimeImmutable($event->opened_at_source->format(DATE_ATOM)),
                    linkedResourceType: $linkedResource['type'] ?? null,
                    linkedResourceId: $linkedResource['id'] ?? null,
                    transitionLineage: (array) $event->transition_lineage,
                );
            }
            $constraints = $this->resourceScope->filterConstraints(
                $scope,
                (int) $state->projectId,
                (int) $state->scheduleId,
                (int) $state->taskId,
                $constraints,
            );
            if ($constraints === null) {
                continue;
            }
            $constraints = $this->filterConstraints($query, $constraints);
            if ($constraints === null) {
                continue;
            }
            $inputs[] = new LookaheadEligibilityInput(
                taskId: $state->taskId,
                container: $state->taskType === 'container',
                status: $state->status,
                plannedStart: $state->plannedStart,
                asOf: $query->asOf,
                constraints: $constraints,
                projectId: $state->projectId,
                scheduleId: $state->scheduleId,
                wbsCode: $state->wbsCode,
                ownerId: $state->ownerId,
                contractorId: $state->contractorId,
                zoneId: $state->zoneId,
                taskType: $state->taskType,
                taskStateVersion: $state->version,
                taskStateSourceHash: $state->sourceHash,
                taskStateEffectiveAt: $state->effectiveAt,
            );
        }
        $effectiveProjectIds = array_values(array_unique(array_map(
            static fn (LookaheadEligibilityInput $input): int => (int) $input->projectId,
            $inputs,
        )));
        sort($effectiveProjectIds, SORT_NUMERIC);
        $policySet = $effectiveProjectIds === []
            ? null
            : $this->policies->activeForProjects(
                $scope->organizationId,
                $effectiveProjectIds,
                $query->asOf,
            );
        $canonicalInputs = array_map(
            static fn (LookaheadEligibilityInput $input): array => $input->canonicalIdentity(),
            $inputs,
        );
        $policyHashes = array_map(
            static fn ($policy): string => $policy->sourceHash,
            $policySet?->all() ?? [],
        );
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'inputs' => $canonicalInputs,
            'policy_hashes' => $policyHashes,
        ])));
        $existing = LookaheadReadinessSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing !== null) {
            return $this->reference($scope, $query, $existing);
        }

        try {
            return DB::transaction(function () use (
                $scope,
                $query,
                $policySet,
                $policyHashes,
                $inputs,
                $sourceHash
            ): ReportSnapshotRef {
                $snapshotId = (string) Str::ulid();
                $metrics = array_map(
                    fn (LookaheadEligibilityInput $input): array => [
                        $input,
                        $this->formula->evaluate(
                            $input,
                            $policySet?->forProject((int) $input->projectId)
                                ?? throw new InvalidArgumentException('lookahead_project_policy_unavailable'),
                        ),
                    ],
                    $inputs,
                );
                $projectionRows = [];
                foreach ($metrics as [$input, $metric]) {
                    $constraints = $input->constraints === [] ? [null] : $input->constraints;
                    foreach ($constraints as $constraint) {
                        $projectionRows[] = [$input, $metric, $constraint];
                    }
                }
                $coverage = $this->formula->summarize(array_column($metrics, 1));
                $totals = [
                    'eligible_tasks' => (int) $coverage->denominator,
                    'ready_tasks' => (int) $coverage->numerator,
                    'readiness_pct' => $coverage->ratio,
                    'hard_blockers' => $coverage->hardBlockers,
                    'soft_blockers' => $coverage->softBlockers,
                    'unknown_metrics' => $this->snapshotUnknownMetrics($metrics),
                ];
                $sourceRefs = [[
                    'source' => 'schedule',
                    'snapshot_kind' => 'lookahead_readiness',
                    'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                    'schema_version' => 'lookahead_events_v1',
                    'watermark' => 'source_'.substr($sourceHash->value, 0, 24),
                    'row_count' => count($projectionRows),
                    'hash' => $sourceHash->value,
                ]];
                $snapshot = LookaheadReadinessSnapshot::query()->create([
                    'id' => $snapshotId,
                    'organization_id' => $scope->organizationId,
                    'policy_version_ids' => array_map(
                        static fn ($policy): int => $policy->policyId ?? $policy->version,
                        $policySet?->all() ?? [],
                    ),
                    'as_of' => $query->asOf,
                    'formula_version' => self::FORMULA_VERSION,
                    'definition_hash' => $query->definition->definitionHash->value,
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'generated_at' => $query->asOf,
                    'stale_at' => $query->asOf->modify('+15 minutes'),
                    'watermarks' => [
                        'policies' => $policyHashes,
                        'events' => 'source_'.substr($sourceHash->value, 0, 24),
                    ],
                    'totals' => $totals,
                    'source_refs' => $sourceRefs,
                    'row_schema' => $this->rowSchema(),
                    'row_count' => count($projectionRows),
                ]);

                $rowBatch = [];
                foreach ($projectionRows as [$input, $metric, $constraint]) {
                    $payload = [
                        ...$input->eligibilityExplanation(),
                        'project_id' => $input->projectId,
                        'schedule_id' => $input->scheduleId,
                        'task_id' => $input->taskId,
                        'planned_start_date' => $input->plannedStart->format('Y-m-d'),
                        'wbs_code' => $input->wbsCode,
                        'owner_id' => $input->ownerId,
                        'contractor_id' => $input->contractorId,
                        'zone_id' => $input->zoneId,
                        'eligible' => $metric->eligible,
                        'ready' => $metric->ready,
                        'blocking_constraint_ids' => $metric->blockingConstraintIds,
                        'hard_blockers' => $metric->hardBlockers,
                        'soft_blockers' => $metric->softBlockers,
                        'constraint_age_days' => $metric->maxConstraintAgeDays,
                        'constraint_id' => $constraint?->constraintId,
                        'constraint_type' => $constraint?->type,
                        'constraint_severity' => $constraint?->severity,
                        'constraint_status' => $constraint?->status,
                        'waiver_until' => $constraint?->waiverUntil?->format(DATE_ATOM),
                        'waiver_evidence_ref' => $constraint?->waiverEvidenceRef,
                        'linked_resource_type' => $constraint?->linkedResourceType,
                        'linked_resource_id' => $constraint?->linkedResourceId,
                        'transition_lineage' => $constraint?->transitionLineage ?? [],
                        'warning_code' => $metric->warningCode,
                        'unknown_metrics' => $metric->warningCode === null ? [] : ['waiver_validity'],
                    ];
                    $rowSourceRefs = [
                        ['type' => 'schedule_task', 'id' => $input->taskId, 'project_id' => $input->projectId],
                        ['type' => 'schedule', 'id' => $input->scheduleId, 'project_id' => $input->projectId],
                        [
                            'type' => 'schedule_task_state_version',
                            'id' => $input->taskStateVersion,
                            'project_id' => $input->projectId,
                            'source_hash' => $input->taskStateSourceHash,
                            'effective_at' => $input->taskStateEffectiveAt?->format(DATE_ATOM),
                        ],
                        ...($constraint === null ? [] : [[
                            'type' => 'work_constraint',
                            'id' => $constraint->constraintId,
                            'project_id' => $input->projectId,
                            'transition_lineage' => $constraint->transitionLineage,
                        ]]),
                        ...($constraint?->linkedResourceId === null ? [] : [[
                            'type' => $constraint->linkedResourceType,
                            'id' => $constraint->linkedResourceId,
                            'project_id' => $input->projectId,
                        ]]),
                    ];
                    $rowBatch[] = [
                        'organization_id' => $scope->organizationId,
                        'snapshot_id' => $snapshotId,
                        'row_key' => implode(':', [
                            $input->projectId,
                            $input->scheduleId,
                            $input->taskId,
                            $constraint?->constraintId ?? 'none',
                        ]),
                        'project_id' => $input->projectId,
                        'schedule_id' => $input->scheduleId,
                        'task_id' => $input->taskId,
                        'constraint_id' => $constraint?->constraintId,
                        'constraint_type' => $constraint?->type,
                        'constraint_status' => $constraint?->status,
                        'planned_start_date' => $input->plannedStart,
                        'wbs_code' => $input->wbsCode,
                        'owner_id' => $input->ownerId,
                        'contractor_id' => $input->contractorId,
                        'zone_id' => $input->zoneId,
                        'severity' => $constraint?->severity,
                        'due_date' => null,
                        'eligible' => $metric->eligible,
                        'ready' => $metric->ready,
                        'age_days' => $metric->maxConstraintAgeDays,
                        'payload' => CanonicalJson::encode($payload),
                        'source_refs' => CanonicalJson::encode($rowSourceRefs),
                    ];
                    if (count($rowBatch) === 500) {
                        DB::table('lookahead_readiness_rows')->insert($rowBatch);
                        $rowBatch = [];
                    }
                }
                if ($rowBatch !== []) {
                    DB::table('lookahead_readiness_rows')->insert($rowBatch);
                }

                return $this->reference($scope, $query, $snapshot);
            });
        } catch (QueryException $exception) {
            $existing = LookaheadReadinessSnapshot::query()
                ->where('organization_id', $scope->organizationId)
                ->where('query_hash', $query->queryHash->value)
                ->where('source_hash', $sourceHash->value)
                ->first();
            if ($existing !== null) {
                return $this->reference($scope, $query, $existing);
            }

            throw new InvalidArgumentException('lookahead_snapshot_conflict', 0, $exception);
        }
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

    private function matchesTaskFilters(ReportQuery $query, object $state): bool
    {
        $values = $query->filters->values;
        $horizonDays = $values['horizon_days'] ?? null;
        if ($horizonDays !== null) {
            if (! is_numeric($horizonDays) || (int) $horizonDays < 1) {
                throw new InvalidArgumentException('lookahead_horizon_filter_invalid');
            }
            $filterEnd = $query->asOf->modify('+'.(int) $horizonDays.' days');
            if ($state->plannedStart < $query->asOf || $state->plannedStart > $filterEnd) {
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

    private function filterConstraints(ReportQuery $query, array $constraints): ?array
    {
        $values = $query->filters->values;
        $types = $values['constraint_types'] ?? $values['types'] ?? [];
        $severities = $values['severities'] ?? [];
        $statuses = $values['statuses'] ?? [];
        if ($types === [] && $severities === [] && $statuses === []) {
            return $constraints;
        }

        $filtered = array_values(array_filter(
            $constraints,
            fn ($constraint): bool => $this->matches($types, $constraint->type)
                && $this->matches($severities, $constraint->severity)
                && $this->matches($statuses, $constraint->status),
        ));

        return $filtered === [] ? null : $filtered;
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

    private function snapshotUnknownMetrics(array $metrics): array
    {
        foreach ($metrics as $entry) {
            $metric = $entry[1] ?? null;
            if ($metric?->warningCode !== null) {
                return ['waiver_validity'];
            }
        }

        return [];
    }

    private function matches(mixed $filter, int|string|null $value): bool
    {
        if ($filter === []) {
            return true;
        }
        if (! is_array($filter) || ! array_is_list($filter) || $value === null) {
            return false;
        }

        return in_array((string) $value, array_map('strval', $filter), true);
    }

    private function reference(
        ReportScope $scope,
        ReportQuery $query,
        LookaheadReadinessSnapshot $snapshot,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            'lookahead_readiness',
            (string) $snapshot->id,
            $scope,
            $query->definition->definitionHash,
            self::FORMULA_VERSION,
            new Sha256Hash((string) $snapshot->source_hash),
            new DateTimeImmutable($snapshot->generated_at->format(DATE_ATOM)),
            $snapshot->stale_at === null ? null : new DateTimeImmutable($snapshot->stale_at->format(DATE_ATOM)),
            (array) $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function rowSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'project_id',
                'wbs_code',
                'task_id',
                'task_status',
                'task_type',
                'task_state_version',
                'task_state_source_hash',
                'task_state_effective_at',
                'planned_start_date',
                'eligible',
                'ready',
                'hard_blockers',
                'soft_blockers',
                'constraint_age_days',
                'constraint_id',
                'constraint_type',
                'constraint_severity',
                'constraint_status',
                'warning_code',
            ],
        );
    }
}
