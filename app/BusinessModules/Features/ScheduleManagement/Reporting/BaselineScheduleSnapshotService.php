<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

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
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\BaselineScheduleVarianceRecord;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class BaselineScheduleSnapshotService
{
    private const FORMULA_VERSION = 'schedule.baseline_variance.v1';

    public function capture(
        ProjectSchedule $schedule,
        int $actorId,
        DateTimeImmutable $capturedAt,
    ): ScheduleBaselineVersion {
        if ($actorId < 1 || (int) $schedule->organization_id < 1 || (int) $schedule->project_id < 1) {
            throw new InvalidArgumentException('schedule_baseline_capture_scope_invalid');
        }

        return DB::transaction(function () use ($schedule, $actorId, $capturedAt): ScheduleBaselineVersion {
            $latestVersion = (int) ScheduleBaselineVersion::query()
                ->where('organization_id', $schedule->organization_id)
                ->where('schedule_id', $schedule->id)
                ->lockForUpdate()
                ->max('version');
            $tasks = $schedule->tasks()
                ->orderBy('id')
                ->get();
            $dependencies = $schedule->dependencies()
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->map(static fn (TaskDependency $dependency): array => [
                    'dependency_id' => (int) $dependency->id,
                    'dependency_type' => $dependency->dependency_type instanceof \BackedEnum
                        ? (string) $dependency->dependency_type->value
                        : (string) $dependency->dependency_type,
                    'lag_days' => (int) ($dependency->lag_days ?? 0),
                    'predecessor_task_id' => (int) $dependency->predecessor_task_id,
                    'successor_task_id' => (int) $dependency->successor_task_id,
                ])
                ->all();
            $payload = $tasks->map(static function (ScheduleTask $task) use ($dependencies): array {
                $taskDependencies = array_values(array_filter(
                    $dependencies,
                    static fn (array $dependency): bool => $dependency['predecessor_task_id'] === (int) $task->id
                        || $dependency['successor_task_id'] === (int) $task->id,
                ));

                return [
                    'baseline_duration_days' => $task->baseline_duration_days,
                    'baseline_end' => $task->baseline_end_date?->format('Y-m-d'),
                    'baseline_start' => $task->baseline_start_date?->format('Y-m-d'),
                    'dependency_refs' => $taskDependencies,
                    'free_float_days' => (int) ($task->free_float_days ?? 0),
                    'is_critical' => (bool) $task->is_critical,
                    'task_id' => (int) $task->id,
                    'task_name' => (string) $task->name,
                    'total_float_days' => (int) ($task->total_float_days ?? 0),
                    'wbs_code' => $task->wbs_code,
                ];
            })->all();
            $criticalPathWatermark = $schedule->critical_path_updated_at?->format(DATE_ATOM)
                ?? 'not_calculated';
            $sourceHash = hash('sha256', CanonicalJson::encode([
                'captured_at' => $capturedAt->format(DATE_ATOM),
                'critical_path_watermark' => $criticalPathWatermark,
                'schedule_id' => (int) $schedule->id,
                'tasks' => $payload,
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
                'source_payload' => $payload,
            ]);

            foreach ($payload as $task) {
                DB::table('schedule_baseline_task_rows')->insert([
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
                ]);
            }

            return $version;
        });
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('baseline_schedule_materialization_identity_invalid');
        }

        $projectIds = $scope->projectIds;
        $requestedProjectIds = $query->filters->values['project_ids'] ?? [];
        if (is_array($requestedProjectIds) && $requestedProjectIds !== []) {
            $projectIds = array_values(array_intersect($projectIds, $requestedProjectIds));
        }

        $schedules = ProjectSchedule::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('is_template', false)
            ->orderBy('id')
            ->get();
        $sourceRows = [];
        $watermarks = [];
        foreach ($schedules as $schedule) {
            $baseline = ScheduleBaselineVersion::query()
                ->where('organization_id', $scope->organizationId)
                ->where('schedule_id', $schedule->id)
                ->where('captured_at', '<=', $query->asOf)
                ->orderByDesc('version')
                ->first();
            $watermarks['schedule_'.(int) $schedule->id] = $baseline === null
                ? 'missing'
                : 'version_'.(int) $baseline->version;
            $baselineTasks = $baseline === null
                ? []
                : collect((array) $baseline->source_payload)->keyBy('task_id')->all();
            $tasks = $schedule->tasks()->orderBy('id')->get();
            foreach ($tasks as $task) {
                $saved = $baselineTasks[(int) $task->id] ?? null;
                $sourceRows[] = [
                    'baseline_version_id' => $baseline?->id,
                    'dependency_refs' => (array) ($saved['dependency_refs'] ?? []),
                    'project_id' => (int) $schedule->project_id,
                    'schedule_id' => (int) $schedule->id,
                    'source' => new BaselineScheduleTaskSource(
                        taskId: (int) $task->id,
                        baselineStart: isset($saved['baseline_start'])
                            ? new DateTimeImmutable($saved['baseline_start'])
                            : null,
                        baselineEnd: isset($saved['baseline_end'])
                            ? new DateTimeImmutable($saved['baseline_end'])
                            : null,
                        plannedStart: new DateTimeImmutable($task->planned_start_date->format('Y-m-d')),
                        plannedEnd: new DateTimeImmutable($task->planned_end_date->format('Y-m-d')),
                        baselineDurationDays: isset($saved['baseline_duration_days'])
                            ? (int) $saved['baseline_duration_days']
                            : null,
                        plannedDurationDays: (int) $task->planned_duration_days,
                        totalFloatDays: (int) ($saved['total_float_days'] ?? $task->total_float_days ?? 0),
                        freeFloatDays: (int) ($saved['free_float_days'] ?? $task->free_float_days ?? 0),
                        critical: (bool) ($saved['is_critical'] ?? $task->is_critical),
                        status: $task->status instanceof \BackedEnum
                            ? (string) $task->status->value
                            : (string) $task->status,
                        scheduleId: (int) $schedule->id,
                        wbsCode: $task->wbs_code,
                        taskName: (string) $task->name,
                    ),
                ];
            }
        }

        $canonicalSource = array_map(
            static fn (array $item): array => [
                'baseline_version_id' => $item['baseline_version_id'],
                'dependency_refs' => $item['dependency_refs'],
                'project_id' => $item['project_id'],
                'schedule_id' => $item['schedule_id'],
                'source' => [
                    'baseline_duration_days' => $item['source']->baselineDurationDays,
                    'baseline_end' => $item['source']->baselineEnd?->format(DATE_ATOM),
                    'baseline_start' => $item['source']->baselineStart?->format(DATE_ATOM),
                    'critical' => $item['source']->critical,
                    'free_float_days' => $item['source']->freeFloatDays,
                    'planned_duration_days' => $item['source']->plannedDurationDays,
                    'planned_end' => $item['source']->plannedEnd->format(DATE_ATOM),
                    'planned_start' => $item['source']->plannedStart->format(DATE_ATOM),
                    'status' => $item['source']->status,
                    'task_id' => $item['source']->taskId,
                    'total_float_days' => $item['source']->totalFloatDays,
                ],
            ],
            $sourceRows,
        );
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'as_of' => $query->asOf->format(DATE_ATOM),
            'rows' => $canonicalSource,
            'watermarks' => $watermarks,
        ])));
        $existing = BaselineScheduleSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing !== null) {
            return $this->reference($scope, $query, $existing);
        }

        try {
            return DB::transaction(function () use ($scope, $query, $sourceRows, $sourceHash, $watermarks): ReportSnapshotRef {
            $snapshotId = (string) Str::ulid();
            $metrics = [];
            $unknown = [];
            foreach ($sourceRows as $sourceRow) {
                $metric = BaselineScheduleVarianceRow::fromSource(
                    $sourceRow['source'],
                    $query->asOf,
                );
                $metrics[] = [$sourceRow, $metric];
                foreach ($metric->warningCodes as $warningCode) {
                    $unknown[$warningCode] = true;
                }
            }
            $totals = [
                'critical_delayed_tasks' => count(array_filter(
                    $metrics,
                    static fn (array $item): bool => $item[1]->critical
                        && ($item[1]->endVarianceDays ?? 0) > 0,
                )),
                'overdue_tasks' => count(array_filter(
                    $metrics,
                    static fn (array $item): bool => $item[1]->overdue,
                )),
                'unknown_metrics' => $unknown === [] ? [] : ['baseline_variance'],
            ];
            $sourceRef = [[
                'source' => 'schedule',
                'snapshot_kind' => 'baseline_schedule',
                'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                'schema_version' => 'schedule_baseline_v1',
                'watermark' => 'source_'.substr($sourceHash->value, 0, 24),
                'row_count' => count($metrics),
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
                'row_count' => count($metrics),
            ]);

            foreach ($metrics as [$sourceRow, $metric]) {
                $payload = $metric->toArray() + [
                    'project_id' => $sourceRow['project_id'],
                    'baseline_version_id' => $sourceRow['baseline_version_id'],
                    'dependency_refs' => $sourceRow['dependency_refs'],
                    'variance_days' => $metric->endVarianceDays,
                    'planned_start' => $sourceRow['source']->plannedStart->format('Y-m-d'),
                    'planned_end' => $sourceRow['source']->plannedEnd->format('Y-m-d'),
                    'unknown_metrics' => $metric->warningCodes === [] ? [] : ['baseline_variance'],
                ];
                BaselineScheduleVarianceRecord::query()->create([
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
                    'payload' => $payload,
                    'source_refs' => [
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
                    ],
                ]);
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
                'wbs_code',
                'task_name',
                'planned_start',
                'planned_end',
                'start_variance_days',
                'end_variance_days',
                'duration_variance_days',
                'total_float_days',
                'critical',
                'status',
            ],
        );
    }
}
