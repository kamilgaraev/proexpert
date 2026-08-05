<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Models\ScheduleBaselineVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\BaselineScheduleTaskSource;
use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\BaselineScheduleVarianceRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\BaselineScheduleSnapshot;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Support\Reporting\DeterministicObjectSpool;
use App\Support\Reporting\ReportScopedResourceFilter;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class BaselineScheduleSnapshotService
{
    private const FORMULA_VERSION = 'schedule.baseline-variance.v1';

    public function __construct(
        private HistoricalScheduleTaskStateQuery $historicalTasks,
    ) {}

    public function capture(
        ProjectSchedule $schedule,
        int $actorId,
        DateTimeImmutable $capturedAt,
    ): ScheduleBaselineVersion {
        if ($actorId < 1 || (int) $schedule->organization_id < 1 || (int) $schedule->project_id < 1) {
            throw new InvalidArgumentException('schedule_baseline_capture_scope_invalid');
        }

        return DB::transaction(function () use ($schedule, $actorId, $capturedAt): ScheduleBaselineVersion {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['schedule-baseline:'.$schedule->organization_id.':'.$schedule->id],
            );
            $latest = ScheduleBaselineVersion::query()
                ->where('organization_id', $schedule->organization_id)
                ->where('schedule_id', $schedule->id)
                ->orderByDesc('version')
                ->first();
            $latestVersion = $latest === null ? 0 : (int) $latest->version;
            $taskSpool = new DeterministicObjectSpool;
            $tasks = $schedule->tasks()
                ->select('schedule_tasks.*')
                ->selectRaw(
                    "COALESCE((
                        SELECT jsonb_agg(
                            jsonb_build_object(
                                'dependency_id', dependency.id,
                                'dependency_type', dependency.dependency_type,
                                'lag_days', COALESCE(dependency.lag_days, 0),
                                'predecessor_task_id', dependency.predecessor_task_id,
                                'successor_task_id', dependency.successor_task_id
                            )
                            ORDER BY dependency.id
                        )
                        FROM task_dependencies dependency
                        WHERE dependency.schedule_id = schedule_tasks.schedule_id
                          AND dependency.is_active = true
                          AND (
                            dependency.predecessor_task_id = schedule_tasks.id
                            OR dependency.successor_task_id = schedule_tasks.id
                          )
                    ), '[]'::jsonb) AS dependency_refs"
                )
                ->orderBy('schedule_tasks.id')
                ->lazyById(500, 'schedule_tasks.id', 'id');
            foreach ($tasks as $task) {
                $payload = [
                    'baseline_duration_days' => $task->baseline_duration_days,
                    'baseline_end' => $task->baseline_end_date?->format('Y-m-d'),
                    'baseline_start' => $task->baseline_start_date?->format('Y-m-d'),
                    'dependency_refs' => $this->jsonArray($task->dependency_refs),
                    'free_float_days' => (int) ($task->free_float_days ?? 0),
                    'is_critical' => (bool) $task->is_critical,
                    'task_id' => (int) $task->id,
                    'task_name' => (string) $task->name,
                    'total_float_days' => (int) ($task->total_float_days ?? 0),
                    'wbs_code' => $task->wbs_code,
                ];
                $taskSpool->append(new \ArrayObject($payload), $payload);
            }
            $criticalPathWatermark = $schedule->critical_path_updated_at?->format(DATE_ATOM)
                ?? 'not_calculated';
            $payloadHash = $taskSpool->sha256();
            $duplicateQuery = ScheduleBaselineVersion::query()
                ->where('organization_id', $schedule->organization_id)
                ->where('schedule_id', $schedule->id)
                ->where('captured_at', $capturedAt)
                ->where('captured_by', $actorId)
                ->where('critical_path_watermark', $criticalPathWatermark)
                ->orderByDesc('version');
            foreach ($duplicateQuery->cursor() as $candidate) {
                $manifest = (array) $candidate->source_payload;
                if (($manifest['schema_version'] ?? null) === 'schedule_baseline_task_rows.v1'
                    && ($manifest['task_row_count'] ?? null) === $taskSpool->count()
                    && is_string($manifest['task_rows_hash'] ?? null)
                    && hash_equals($manifest['task_rows_hash'], $payloadHash)
                ) {
                    return $candidate;
                }
            }
            $sourceHash = hash('sha256', CanonicalJson::encode([
                'captured_at' => $capturedAt->format(DATE_ATOM),
                'critical_path_watermark' => $criticalPathWatermark,
                'schedule_id' => (int) $schedule->id,
                'task_row_count' => $taskSpool->count(),
                'task_rows_hash' => $payloadHash,
                'version' => $latestVersion + 1,
            ]));

            $version = ScheduleBaselineVersion::query()->create([
                'organization_id' => (int) $schedule->organization_id,
                'project_id' => (int) $schedule->project_id,
                'schedule_id' => (int) $schedule->id,
                'version' => $latestVersion + 1,
                'captured_at' => $capturedAt,
                'captured_by' => $actorId,
                'critical_path_watermark' => $criticalPathWatermark,
                'source_hash' => $sourceHash,
                'source_payload' => [
                    'schema_version' => 'schedule_baseline_task_rows.v1',
                    'task_row_count' => $taskSpool->count(),
                    'task_rows_hash' => $payloadHash,
                ],
            ]);

            $rowBatch = [];
            foreach ($taskSpool->items() as $taskPayload) {
                if (! $taskPayload instanceof \ArrayObject) {
                    throw new InvalidArgumentException('schedule_baseline_task_spool_invalid');
                }
                $task = $taskPayload->getArrayCopy();
                $rowBatch[] = [
                    'organization_id' => (int) $schedule->organization_id,
                    'baseline_version_id' => (int) $version->id,
                    'schedule_id' => (int) $schedule->id,
                    'task_id' => $task['task_id'],
                    'wbs_code' => $task['wbs_code'],
                    'task_name' => $task['task_name'],
                    'baseline_start' => $task['baseline_start'],
                    'baseline_end' => $task['baseline_end'],
                    'baseline_duration_days' => $task['baseline_duration_days'],
                    'total_float_days' => $task['total_float_days'],
                    'free_float_days' => $task['free_float_days'],
                    'is_critical' => $task['is_critical'],
                    'dependency_refs' => CanonicalJson::encode($task['dependency_refs']),
                ];
                if (count($rowBatch) === 500) {
                    DB::table('schedule_baseline_task_rows')->insert($rowBatch);
                    $rowBatch = [];
                }
            }
            if ($rowBatch !== []) {
                DB::table('schedule_baseline_task_rows')->insert($rowBatch);
            }

            return $version;
        });
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if (DB::transactionLevel() > 0) {
            return $this->materializeWithinSnapshot($scope, $query);
        }

        return DB::transaction(function () use ($scope, $query): ReportSnapshotRef {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->materializeWithinSnapshot($scope, $query);
        }, 3);
    }

    private function materializeWithinSnapshot(
        ReportScope $scope,
        ReportQuery $query,
    ): ReportSnapshotRef {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('baseline_schedule_materialization_identity_invalid');
        }
        $this->assertPublicFilterValues($query);
        $asOfFilter = $query->filters->values['as_of'] ?? null;
        if ($asOfFilter !== null
            && (! is_string($asOfFilter)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOfFilter) !== 1
                || $asOfFilter !== $query->asOf->format('Y-m-d'))
        ) {
            throw new InvalidArgumentException('baseline_schedule_as_of_filter_invalid');
        }

        $projectIds = array_values(array_intersect(
            $scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?: $scope->projectIds,
        ));
        if ($projectIds === []) {
            throw new InvalidArgumentException('baseline_schedule_project_filter_empty');
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
        $scheduleIds = $this->positiveIntegerFilter($query, 'schedule_ids');
        $allowedScheduleIds = $this->intersectNullableIds(
            $scopedScheduleIds,
            $scheduleIds === [] ? null : $scheduleIds,
        );
        $missingStateExists = ScheduleTask::withTrashed()
            ->where('organization_id', $scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereHas('schedule', static function ($builder) use (
                $projectIds,
                $query,
                $allowedScheduleIds,
            ): void {
                $builder
                    ->whereIn('project_id', $projectIds)
                    ->where('created_at', '<=', $query->asOf)
                    ->where('is_template', false)
                    ->when(
                        $allowedScheduleIds !== null,
                        static fn ($scheduleBuilder) => $scheduleBuilder
                            ->whereIn('id', $allowedScheduleIds),
                    );
            })
            ->when(
                $scopedTaskIds !== null,
                static fn ($builder) => $builder->whereIn('id', $scopedTaskIds),
            )
            ->whereNotExists(static function ($builder) use ($scope, $query): void {
                $builder
                    ->selectRaw('1')
                    ->from('schedule_task_state_versions as state_coverage')
                    ->whereColumn('state_coverage.task_id', 'schedule_tasks.id')
                    ->where('state_coverage.organization_id', $scope->organizationId)
                    ->where('state_coverage.effective_at', '<=', $query->asOf);
            })
            ->exists();
        if ($missingStateExists) {
            throw new InvalidArgumentException('historical_schedule_task_state_incomplete');
        }

        $states = $this->historicalTasks
            ->latestForLookaheadCursor(
                $scope->organizationId,
                $projectIds,
                $query->asOf,
                $allowedScheduleIds,
                $scopedTaskIds,
            )
            ->filter(fn ($state): bool => $state->active
                && $this->matchesTaskFilters($query, $state));
        $sourceSpool = new DeterministicObjectSpool;
        $baselineWatermark = 0;
        $missingBaselineRows = 0;
        foreach ($states->chunk(500) as $statePage) {
            $pageTaskIds = [];
            $pageScheduleIds = [];
            foreach ($statePage as $state) {
                $pageTaskIds[] = (int) $state->taskId;
                $pageScheduleIds[(int) $state->scheduleId] = true;
            }
            $baselineBySchedule = [];
            $baselineQuery = ScheduleBaselineVersion::query()
                ->where('organization_id', $scope->organizationId)
                ->whereIn('schedule_id', array_keys($pageScheduleIds))
                ->where('captured_at', '<=', $query->asOf)
                ->whereRaw(
                    'NOT EXISTS (
                        SELECT 1 FROM schedule_baseline_versions baseline_later
                        WHERE baseline_later.organization_id = schedule_baseline_versions.organization_id
                          AND baseline_later.schedule_id = schedule_baseline_versions.schedule_id
                          AND baseline_later.captured_at <= ?
                          AND baseline_later.version > schedule_baseline_versions.version
                    )',
                    [$query->asOf->format(DATE_ATOM)],
                )
                ->orderBy('id');
            foreach ($baselineQuery->cursor() as $baseline) {
                $baselineBySchedule[(int) $baseline->schedule_id] = $baseline;
                $baselineWatermark = max($baselineWatermark, (int) $baseline->id);
            }
            $baselineRows = [];
            $baselineIds = array_map(
                static fn (ScheduleBaselineVersion $baseline): int => (int) $baseline->id,
                $baselineBySchedule,
            );
            if ($baselineIds !== []) {
                $rowQuery = DB::table('schedule_baseline_task_rows')
                    ->where('organization_id', $scope->organizationId)
                    ->whereIn('baseline_version_id', $baselineIds)
                    ->whereIn('task_id', $pageTaskIds)
                    ->orderBy('id');
                foreach ($rowQuery->cursor() as $row) {
                    $baselineRows[(int) $row->baseline_version_id.':'.(int) $row->task_id] = $row;
                }
            }
            foreach ($statePage as $state) {
                $scheduleId = (int) $state->scheduleId;
                $baseline = $baselineBySchedule[$scheduleId] ?? null;
                $saved = $baseline === null
                    ? null
                    : ($baselineRows[(int) $baseline->id.':'.(int) $state->taskId] ?? null);
                if ($saved === null) {
                    $missingBaselineRows++;
                }
                $dependencyRefs = $saved === null
                    ? []
                    : $this->jsonArray($saved->dependency_refs);
                $source = new BaselineScheduleTaskSource(
                    taskId: $state->taskId,
                    baselineStart: $saved?->baseline_start === null
                        ? null
                        : new DateTimeImmutable((string) $saved->baseline_start),
                    baselineEnd: $saved?->baseline_end === null
                        ? null
                        : new DateTimeImmutable((string) $saved->baseline_end),
                    plannedStart: $state->plannedStart,
                    plannedEnd: $state->plannedEnd,
                    baselineDurationDays: $saved?->baseline_duration_days === null
                        ? null
                        : (int) $saved->baseline_duration_days,
                    plannedDurationDays: $state->plannedDurationDays,
                    totalFloatDays: (int) ($saved?->total_float_days ?? $state->totalFloatDays),
                    freeFloatDays: (int) ($saved?->free_float_days ?? $state->freeFloatDays),
                    critical: (bool) ($saved?->is_critical ?? $state->critical),
                    status: $state->status,
                    scheduleId: $scheduleId,
                    wbsCode: $state->wbsCode,
                    taskName: $state->taskName,
                );
                $sourceRow = [
                    'baseline_version_id' => $baseline?->id,
                    'dependency_refs' => $dependencyRefs,
                    'project_id' => $state->projectId,
                    'schedule_id' => $scheduleId,
                    'state_hash' => $state->sourceHash,
                    'source' => $source,
                ];
                $sourceSpool->append(
                    new \ArrayObject($sourceRow),
                    $this->sourceIdentity($sourceRow),
                );
            }
        }

        $watermarks = [
            'baseline_version_id' => $baselineWatermark,
            'missing_baseline_rows' => $missingBaselineRows,
            'source_rows_hash' => $sourceSpool->sha256(),
        ];
        $sourceHashContext = hash_init('sha256');
        hash_update(
            $sourceHashContext,
            '{"as_of":'.CanonicalJson::encode($query->asOf->format(DATE_ATOM)).',"rows":',
        );
        $sourceSpool->updateCanonicalArrayHash($sourceHashContext);
        hash_update(
            $sourceHashContext,
            ',"watermarks":'.CanonicalJson::encode($watermarks).'}',
        );
        $sourceHash = new Sha256Hash(hash_final($sourceHashContext));
        $existing = BaselineScheduleSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing !== null) {
            return $this->reference($scope, $query, $existing);
        }

        $metricSpool = new DeterministicObjectSpool;
        $baselineMissingTasks = 0;
        $criticalTasks = 0;
        $criticalDelayedTasks = 0;
        $delayedTasks = 0;
        $overdueTasks = 0;
        $unknown = false;
        foreach ($sourceSpool->items() as $sourcePayload) {
            if (! $sourcePayload instanceof \ArrayObject) {
                throw new InvalidArgumentException('baseline_schedule_source_spool_invalid');
            }
            $sourceRow = $sourcePayload->getArrayCopy();
            $metric = BaselineScheduleVarianceRow::fromSource(
                $sourceRow['source'],
                $query->asOf,
            );
            $baselineMissingTasks += (int) ($metric->warningCodes !== []);
            $criticalTasks += (int) $metric->critical;
            $delayedTasks += (int) (($metric->endVarianceDays ?? 0) > 0);
            $criticalDelayedTasks += (int) (
                $metric->critical && ($metric->endVarianceDays ?? 0) > 0
            );
            $overdueTasks += (int) $metric->overdue;
            $unknown = $unknown || $metric->warningCodes !== [];
            $metricSpool->append(
                new \ArrayObject([$sourceRow, $metric]),
                [
                    'row_key' => implode(':', [
                        $sourceRow['schedule_id'],
                        $metric->taskId,
                        $sourceRow['baseline_version_id'] ?? 'missing',
                    ]),
                    'state_hash' => $sourceRow['state_hash'],
                ],
            );
        }
        $totals = [
            'task_count' => $metricSpool->count(),
            'baseline_missing_tasks' => $baselineMissingTasks,
            'critical_tasks' => $criticalTasks,
            'delayed_tasks' => $delayedTasks,
            'critical_delayed_tasks' => $criticalDelayedTasks,
            'overdue_tasks' => $overdueTasks,
            'unknown_metrics' => $unknown ? ['baseline_variance'] : [],
        ];

        try {
            return DB::transaction(function () use (
                $scope,
                $query,
                $sourceHash,
                $watermarks,
                $metricSpool,
                $totals,
            ): ReportSnapshotRef {
                $snapshotId = (string) Str::ulid();
                $sourceRef = [[
                    'source' => 'schedule',
                    'snapshot_kind' => 'baseline_schedule',
                    'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                    'schema_version' => 'schedule_baseline_v1',
                    'watermark' => 'source_'.substr($sourceHash->value, 0, 24),
                    'row_count' => $metricSpool->count(),
                    'hash' => $sourceHash->value,
                ]];
                $rowSchema = $this->rowSchema();
                $snapshot = BaselineScheduleSnapshot::query()->create([
                    'id' => $snapshotId,
                    'organization_id' => $scope->organizationId,
                    'as_of' => $query->asOf,
                    'formula_version' => self::FORMULA_VERSION,
                    'definition_hash' => $query->definition->definitionHash->value,
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'generated_at' => $query->asOf,
                    'stale_at' => $query->asOf->modify('+1 day'),
                    'watermarks' => $watermarks,
                    'totals' => $totals,
                    'source_refs' => $sourceRef,
                    'row_schema' => $rowSchema,
                    'row_count' => $metricSpool->count(),
                ]);

                $rowBatch = [];
                foreach ($metricSpool->items() as $metricPayload) {
                    if (! $metricPayload instanceof \ArrayObject) {
                        throw new InvalidArgumentException('baseline_schedule_metric_spool_invalid');
                    }
                    [$sourceRow, $metric] = $metricPayload->getArrayCopy();
                    $payload = $metric->toArray() + [
                        'project_id' => $sourceRow['project_id'],
                        'baseline_version_id' => $sourceRow['baseline_version_id'],
                        'dependency_refs' => $sourceRow['dependency_refs'],
                        'variance_days' => $metric->endVarianceDays,
                        'planned_start' => $sourceRow['source']->plannedStart->format('Y-m-d'),
                        'planned_end' => $sourceRow['source']->plannedEnd->format('Y-m-d'),
                        'unknown_metrics' => $metric->warningCodes === [] ? [] : ['baseline_variance'],
                    ];
                    $sourceRefs = [
                        ['type' => 'schedule_task', 'id' => $metric->taskId, 'project_id' => $sourceRow['project_id']],
                        ['type' => 'schedule', 'id' => $sourceRow['schedule_id'], 'project_id' => $sourceRow['project_id']],
                        ...array_map(
                            static fn (array $dependency): array => [
                                'type' => 'task_dependency',
                                'id' => $dependency['dependency_id'],
                                'project_id' => $sourceRow['project_id'],
                            ],
                            $sourceRow['dependency_refs'],
                        ),
                    ];
                    $rowBatch[] = [
                        'organization_id' => $scope->organizationId,
                        'snapshot_id' => $snapshotId,
                        'row_key' => implode(':', [
                            $sourceRow['schedule_id'],
                            $metric->taskId,
                            $sourceRow['baseline_version_id'] ?? 'missing',
                        ]),
                        'schedule_id' => $sourceRow['schedule_id'],
                        'task_id' => $metric->taskId,
                        'baseline_version_id' => $sourceRow['baseline_version_id'],
                        'wbs_code' => $metric->wbsCode,
                        'task_name' => $metric->taskName,
                        'planned_start' => $sourceRow['source']->plannedStart,
                        'planned_end' => $sourceRow['source']->plannedEnd,
                        'variance_days' => $metric->endVarianceDays,
                        'total_float_days' => $metric->totalFloatDays,
                        'is_critical' => $metric->critical,
                        'status' => $metric->status,
                        'payload' => CanonicalJson::encode($payload),
                        'source_refs' => CanonicalJson::encode($sourceRefs),
                    ];
                    if (count($rowBatch) === 500) {
                        DB::table('baseline_schedule_variance_rows')->insert($rowBatch);
                        $rowBatch = [];
                    }
                }
                if ($rowBatch !== []) {
                    DB::table('baseline_schedule_variance_rows')->insert($rowBatch);
                }

                return $this->reference($scope, $query, $snapshot);
            });
        } catch (QueryException $exception) {
            $existing = BaselineScheduleSnapshot::query()
                ->where('organization_id', $scope->organizationId)
                ->where('query_hash', $query->queryHash->value)
                ->where('source_hash', $sourceHash->value)
                ->first();
            if ($existing !== null) {
                return $this->reference($scope, $query, $existing);
            }

            throw new InvalidArgumentException('baseline_schedule_snapshot_conflict', 0, $exception);
        }
    }

    private function reference(
        ReportScope $scope,
        ReportQuery $query,
        BaselineScheduleSnapshot $snapshot,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            kind: 'baseline_schedule_variance',
            id: (string) $snapshot->id,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: self::FORMULA_VERSION,
            sourceHash: new Sha256Hash((string) $snapshot->source_hash),
            generatedAt: new DateTimeImmutable($snapshot->generated_at->format(DATE_ATOM)),
            staleAt: $snapshot->stale_at === null
                ? null
                : new DateTimeImmutable($snapshot->stale_at->format(DATE_ATOM)),
            watermarks: (array) $snapshot->watermarks,
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function rowSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'row_key',
                'wbs_code',
                'task_name',
                'planned_start',
                'planned_end',
                'start_variance_days',
                'end_variance_days',
                'duration_variance_days',
                'total_float_days',
                'free_float_days',
                'critical',
                'overdue',
                'overdue_days',
                'status',
                'warning_codes',
            ],
        );
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

    private function assertPublicFilterValues(ReportQuery $query): void
    {
        $statuses = $query->filters->values['statuses'] ?? [];
        $allowedStatuses = array_map(
            static fn (TaskStatusEnum $status): string => $status->value,
            TaskStatusEnum::cases(),
        );
        if (! is_array($statuses)
            || ! array_is_list($statuses)
            || array_filter(
                $statuses,
                static fn (mixed $status): bool => ! is_string($status)
                    || ! in_array($status, $allowedStatuses, true),
            ) !== []
            || (array_key_exists('critical', $query->filters->values)
                && ! is_bool($query->filters->values['critical']))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }
    }

    private function sourceIdentity(array $sourceRow): array
    {
        $source = $sourceRow['source'];
        if (! $source instanceof BaselineScheduleTaskSource) {
            throw new InvalidArgumentException('baseline_schedule_source_invalid');
        }

        return [
            'baseline_version_id' => $sourceRow['baseline_version_id'],
            'dependency_refs' => $sourceRow['dependency_refs'],
            'project_id' => $sourceRow['project_id'],
            'schedule_id' => $sourceRow['schedule_id'],
            'state_hash' => $sourceRow['state_hash'],
            'source' => [
                'baseline_duration_days' => $source->baselineDurationDays,
                'baseline_end' => $source->baselineEnd?->format(DATE_ATOM),
                'baseline_start' => $source->baselineStart?->format(DATE_ATOM),
                'critical' => $source->critical,
                'free_float_days' => $source->freeFloatDays,
                'planned_duration_days' => $source->plannedDurationDays,
                'planned_end' => $source->plannedEnd->format(DATE_ATOM),
                'planned_start' => $source->plannedStart->format(DATE_ATOM),
                'status' => $source->status,
                'task_id' => $source->taskId,
                'total_float_days' => $source->totalFloatDays,
            ],
        ];
    }

    private function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('schedule_baseline_dependency_refs_invalid');
        }

        return $value;
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

    private function positiveIntegerFilter(ReportQuery $query, string $key): array
    {
        $values = $query->filters->values[$key] ?? [];
        if ($values === []) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('baseline_schedule_filter_invalid');
        }

        $result = array_map('intval', $values);
        if (array_filter($result, static fn (int $value): bool => $value < 1) !== []) {
            throw new InvalidArgumentException('baseline_schedule_filter_invalid');
        }

        return array_values(array_unique($result));
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
}
