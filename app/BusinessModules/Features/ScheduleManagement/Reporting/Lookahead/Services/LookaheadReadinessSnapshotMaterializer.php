<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\Models\ScheduleTask;
use DateInterval;
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
    ) {
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('lookahead_materialization_identity_invalid');
        }
        $policy = $this->policies->active($scope->organizationId, $scope->projectIds, $query->asOf);
        $horizonEnd = $query->asOf->add(new DateInterval('P'.$policy->horizonDays.'D'));
        $tasks = ScheduleTask::query()
            ->where('organization_id', $scope->organizationId)
            ->whereHas('schedule', fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->whereIn('status', $policy->eligibleTaskStatuses)
            ->whereBetween('planned_start_date', [
                $query->asOf->format('Y-m-d'),
                $horizonEnd->format('Y-m-d'),
            ])
            ->where('task_type', '!=', 'container')
            ->with('schedule:id,project_id')
            ->orderBy('id')
            ->get();
        $taskIds = $tasks->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $events = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('task_id', $taskIds)
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
        $uncapturedConstraintExists = WorkConstraint::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('schedule_task_id', $taskIds)
            ->where('created_at', '<=', $query->asOf)
            ->when(
                $capturedConstraintIds !== [],
                static fn ($builder) => $builder->whereNotIn('id', $capturedConstraintIds),
            )
            ->exists();
        if ($uncapturedConstraintExists) {
            throw new InvalidArgumentException('lookahead_constraint_history_incomplete');
        }
        $inputs = [];
        foreach ($tasks as $task) {
            $constraints = [];
            foreach ($events->get((int) $task->id, []) as $event) {
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
                );
            }
            $inputs[] = new LookaheadEligibilityInput(
                taskId: (int) $task->id,
                container: false,
                status: $task->status instanceof \BackedEnum ? (string) $task->status->value : (string) $task->status,
                plannedStart: new DateTimeImmutable($task->planned_start_date->format('Y-m-d')),
                asOf: $query->asOf,
                constraints: $constraints,
                projectId: (int) $task->schedule->project_id,
                scheduleId: (int) $task->schedule_id,
                wbsCode: $task->wbs_code,
                ownerId: $task->assigned_user_id === null ? null : (int) $task->assigned_user_id,
            );
        }
        $canonicalInputs = array_map(
            static fn (LookaheadEligibilityInput $input): array => [
                'as_of' => $input->asOf->format(DATE_ATOM),
                'constraints' => array_map(
                    static fn (LookaheadConstraintState $constraint): array => [
                        'constraint_id' => $constraint->constraintId,
                        'opened_at' => $constraint->openedAt->format(DATE_ATOM),
                        'severity' => $constraint->severity,
                        'status' => $constraint->status,
                        'type' => $constraint->type,
                        'linked_resource_id' => $constraint->linkedResourceId,
                        'linked_resource_type' => $constraint->linkedResourceType,
                        'waiver_evidence_ref' => $constraint->waiverEvidenceRef,
                        'waiver_until' => $constraint->waiverUntil?->format(DATE_ATOM),
                    ],
                    $input->constraints,
                ),
                'planned_start' => $input->plannedStart->format(DATE_ATOM),
                'project_id' => $input->projectId,
                'schedule_id' => $input->scheduleId,
                'status' => $input->status,
                'task_id' => $input->taskId,
            ],
            $inputs,
        );
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'inputs' => $canonicalInputs,
            'policy_hash' => $policy->sourceHash,
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
            return DB::transaction(function () use ($scope, $query, $policy, $inputs, $sourceHash): ReportSnapshotRef {
            $snapshotId = (string) Str::ulid();
            $metrics = array_map(
                fn (LookaheadEligibilityInput $input): array => [$input, $this->formula->evaluate($input, $policy)],
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
                'policy_version_id' => $policy->policyId ?? $policy->version,
                'as_of' => $query->asOf,
                'formula_version' => self::FORMULA_VERSION,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'generated_at' => $query->asOf,
                'stale_at' => $query->asOf->modify('+15 minutes'),
                'watermarks' => [
                    'policy' => 'version_'.$policy->version,
                    'events' => 'source_'.substr($sourceHash->value, 0, 24),
                ],
                'totals' => $totals,
                'source_refs' => $sourceRefs,
                'row_schema' => $this->rowSchema(),
                'row_count' => count($projectionRows),
            ]);

            foreach ($projectionRows as [$input, $metric, $constraint]) {
                $payload = [
                    'project_id' => $input->projectId,
                    'schedule_id' => $input->scheduleId,
                    'task_id' => $input->taskId,
                    'planned_start_date' => $input->plannedStart->format('Y-m-d'),
                    'wbs_code' => $input->wbsCode,
                    'owner_id' => $input->ownerId,
                    'contractor_id' => $input->contractorId,
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
                    'warning_code' => $metric->warningCode,
                    'unknown_metrics' => $metric->warningCode === null ? [] : ['waiver_validity'],
                ];
                LookaheadReadinessRow::query()->create([
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
                    'severity' => $constraint?->severity,
                    'due_date' => null,
                    'eligible' => $metric->eligible,
                    'ready' => $metric->ready,
                    'age_days' => $metric->maxConstraintAgeDays,
                    'payload' => $payload,
                    'source_refs' => [
                        ['type' => 'schedule_task', 'id' => $input->taskId, 'project_id' => $input->projectId],
                        ...($constraint === null ? [] : [[
                            'type' => 'work_constraint',
                            'id' => $constraint->constraintId,
                            'project_id' => $input->projectId,
                        ]]),
                        ...($constraint?->linkedResourceId === null ? [] : [[
                            'type' => $constraint->linkedResourceType,
                            'id' => $constraint->linkedResourceId,
                            'project_id' => $input->projectId,
                        ]]),
                    ],
                ]);
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
            if (!is_array($reference)
                || ($reference['type'] ?? null) === 'waiver_evidence'
                || !is_string($reference['type'] ?? null)
                || !is_numeric($reference['id'] ?? null)
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
