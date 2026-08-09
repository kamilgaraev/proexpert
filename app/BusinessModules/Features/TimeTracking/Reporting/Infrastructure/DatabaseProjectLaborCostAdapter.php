<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactLease;
use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactMaterializer;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateResolution;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DatabaseProjectLaborCostAdapter implements ProjectLaborCostDatabasePort
{
    public const FORMULA_VERSION = 'labor-cost.v1';

    public const SCHEMA_VERSION = 'project-labor-cost-source.v1';

    public const SORTS = [
        'work_date', 'employee_name', 'project_name', 'contractor_name', 'task_name',
        'hours', 'planned_hours', 'rate', 'cost', 'variance', 'cost_per_accepted_unit',
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private ProjectLaborCostFormula $formula,
    ) {}

    public function forEmployee(int $organizationId, int $employeeId): array
    {
        return $this->connection->table('time_tracking_labor_rate_versions')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): EffectiveLaborRateFact => new EffectiveLaborRateFact(
                rateId: (int) $row->id,
                organizationId: (int) $row->organization_id,
                employeeId: (int) $row->employee_id,
                amount: (string) $row->amount,
                currency: $row->currency === null ? null : (string) $row->currency,
                rateType: (string) $row->rate_type,
                validFrom: new DateTimeImmutable((string) $row->valid_from),
                validToExclusive: $row->valid_to_exclusive === null
                    ? null
                    : new DateTimeImmutable((string) $row->valid_to_exclusive),
                sourceVersion: (int) $row->version,
            ))
            ->all();
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->organizationId !== $query->scope->organizationId) {
            throw new InvalidArgumentException('project_labor_cost_scope_invalid');
        }
        $ownerFacts = $this->materializeTemporalOwnerFacts($scope, $query, [
            'time_entries',
            'completed_works',
            'time_tracking_labor_rate_versions',
            'workforce_employees',
            'projects',
            'project_schedules',
            'schedule_tasks',
            'work_types',
            'measurement_units',
            'contractors',
        ]);
        [$periodFrom, $periodTo] = $this->period($query);
        $projectIds = $this->projectIds($scope, $query);
        $employeeIds = $this->authorizedIds($scope, 'employee', $this->ids($query, 'employee_ids'));
        $taskIds = $this->authorizedIds($scope, 'task', $this->ids($query, 'task_ids'));
        $workTypeIds = $this->authorizedIds($scope, 'work_type', $this->ids($query, 'work_type_ids'));
        $contractorIds = $this->authorizedIds($scope, 'contractor', $this->ids($query, 'contractor_ids'));
        $statuses = $this->strings($query, 'statuses');
        $this->assertOrganizationIds('workforce_employees', $scope, $employeeIds);
        $this->assertOrganizationIds('schedule_tasks', $scope, $taskIds);
        $this->assertOrganizationIds('work_types', $scope, $workTypeIds);
        $this->assertOrganizationIds('contractors', $scope, $contractorIds);
        $billable = $query->filters->values['billable'] ?? null;
        if ($billable !== null && ! is_bool($billable)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        $entries = $this->connection->table('time_entries as entry')
            ->join('workforce_employees as employee', function ($join): void {
                $join->on('employee.user_id', '=', 'entry.user_id')
                    ->on('employee.organization_id', '=', 'entry.organization_id');
            })
            ->join('projects as project', function ($join): void {
                $join->on('project.id', '=', 'entry.project_id')
                    ->on('project.organization_id', '=', 'entry.organization_id');
            })
            ->leftJoin('schedule_tasks as task', function ($join): void {
                $join->on('task.id', '=', 'entry.task_id')
                    ->on('task.organization_id', '=', 'entry.organization_id');
            })
            ->leftJoin('project_schedules as schedule', function ($join): void {
                $join->on('schedule.id', '=', 'task.schedule_id')
                    ->on('schedule.organization_id', '=', 'entry.organization_id');
            })
            ->leftJoin('work_types as work_type', function ($join): void {
                $join->on('work_type.id', '=', 'entry.work_type_id')
                    ->on('work_type.organization_id', '=', 'entry.organization_id');
            })
            ->leftJoin('measurement_units as measurement_unit', function ($join): void {
                $join->on('measurement_unit.id', '=', 'work_type.measurement_unit_id')
                    ->on('measurement_unit.organization_id', '=', 'entry.organization_id');
            })
            ->where('entry.organization_id', $scope->organizationId)
            ->where('entry.status', 'approved')
            ->where('entry.approved_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->whereNull('entry.deleted_at')
            ->whereNull('employee.deleted_at')
            ->whereBetween('entry.work_date', [$periodFrom, $periodTo])
            ->when(
                $projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('entry.project_id', $projectIds),
            )
            ->when(
                $employeeIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('employee.id', $employeeIds),
            )
            ->when(
                $taskIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('entry.task_id', $taskIds),
            )
            ->when(
                $workTypeIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('entry.work_type_id', $workTypeIds),
            )
            ->when(
                $billable !== null,
                static fn (Builder $builder): Builder => $builder->where('entry.is_billable', $billable),
            )
            ->when(
                $statuses !== [] && ! in_array('approved', $statuses, true),
                static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
            )
            ->select([
                'entry.id',
                'entry.organization_id',
                'entry.user_id',
                'entry.project_id',
                'entry.task_id',
                'entry.work_type_id',
                'entry.work_date',
                'entry.status',
                'entry.hours_worked',
                'entry.is_billable',
                'employee.id as employee_id',
                'employee.last_name',
                'employee.first_name',
                'employee.middle_name',
                'project.name as project_name',
                'task.name as task_name',
                'task.planned_work_hours',
                'task.planned_duration_days',
                'schedule.project_id as task_project_id',
                'work_type.name as work_type_name',
                'measurement_unit.short_name as accepted_unit',
            ])
            ->orderBy('entry.work_date')
            ->orderBy('entry.id')
            ->get();
        foreach ($entries as $entry) {
            if ($entry->task_project_id !== null && (int) $entry->task_project_id !== (int) $entry->project_id) {
                throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
            }
        }
        $acceptedWorks = $entries->isEmpty()
            ? collect()
            : $this->connection->table('completed_works as accepted')
                ->leftJoin('contractors as contractor', function ($join): void {
                    $join->on('contractor.id', '=', 'accepted.contractor_id')
                        ->on('contractor.organization_id', '=', 'accepted.organization_id');
                })
                ->where('accepted.organization_id', $scope->organizationId)
                ->where('accepted.created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->where('accepted.status', 'confirmed')
                ->whereNull('accepted.deleted_at')
                ->whereBetween('accepted.completion_date', [$periodFrom, $periodTo])
                ->when(
                    $projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('accepted.project_id', $projectIds),
                )
                ->when(
                    $contractorIds !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('accepted.contractor_id', $contractorIds),
                )
                ->orderBy('accepted.id')
                ->get([
                    'accepted.id',
                    'accepted.user_id',
                    'accepted.project_id',
                    'accepted.schedule_task_id',
                    'accepted.work_type_id',
                    'accepted.completion_date',
                    'accepted.contractor_id',
                    'accepted.quantity',
                    'accepted.completed_quantity',
                    'contractor.name as contractor_name',
                ])
                ->groupBy(static fn (object $work): string => implode(':', [
                    $work->user_id,
                    $work->project_id,
                    $work->schedule_task_id ?? 'none',
                    $work->work_type_id,
                    $work->completion_date,
                ]));
        $entries = $entries->filter(function (object $entry) use ($acceptedWorks, $contractorIds): bool {
            $accepted = $this->acceptedWorkForEntry($acceptedWorks, $entry);

            return $contractorIds === [] || ($accepted !== null
                && $accepted->contractor_id !== null
                && in_array((int) $accepted->contractor_id, $contractorIds, true));
        })->values();
        $sourceCount = $entries->count();
        $rateEmployeeIds = $entries->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $ratesByEmployee = $rateEmployeeIds === []
            ? collect()
            : $this->connection->table('time_tracking_labor_rate_versions')
                ->where('organization_id', $scope->organizationId)
                ->whereIn('employee_id', $rateEmployeeIds)
                ->where('status', 'approved')
                ->where('approved_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->whereDate('valid_from', '<=', $periodTo)
                ->orderBy('employee_id')
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');
        $rows = [];
        $warnings = [];
        foreach ($entries as $entry) {
            $acceptedWork = $this->acceptedWorkForEntry($acceptedWorks, $entry);
            $acceptedUnits = $acceptedWork === null
                ? null
                : (string) ($acceptedWork->completed_quantity ?? $acceptedWork->quantity);
            $fact = new ProjectLaborEntryFact(
                timeEntryId: (int) $entry->id,
                organizationId: (int) $entry->organization_id,
                employeeId: (int) $entry->employee_id,
                projectId: (int) $entry->project_id,
                taskId: $entry->task_id === null ? null : (int) $entry->task_id,
                workTypeId: $entry->work_type_id === null ? null : (int) $entry->work_type_id,
                acceptedWorkId: $acceptedWork === null ? null : (int) $acceptedWork->id,
                workDate: new DateTimeImmutable((string) $entry->work_date),
                status: (string) $entry->status,
                hours: (string) $entry->hours_worked,
                billable: (bool) $entry->is_billable,
                acceptedUnits: $acceptedUnits,
                acceptedUnit: $acceptedUnits === null ? null : $entry->accepted_unit,
            );
            $resolution = $this->effectiveRate($ratesByEmployee, (int) $entry->employee_id, $fact->workDate);
            $plannedHours = $this->plannedHours($entry);
            $metrics = $this->formula->calculate($fact, $resolution, $plannedHours);
            if ($metrics === null) {
                continue;
            }
            $warnings = array_merge($warnings, $metrics->qualityWarnings);
            $row = [
                'row_key' => hash('sha256', (string) $entry->id),
                'time_entry_id' => (int) $entry->id,
                'work_date' => $fact->workDate->format('Y-m-d'),
                'employee_id' => (int) $entry->employee_id,
                'employee_name' => trim(implode(' ', array_filter([
                    $entry->last_name,
                    $entry->first_name,
                    $entry->middle_name,
                ]))),
                'project_id' => (int) $entry->project_id,
                'project_name' => (string) $entry->project_name,
                'contractor_id' => $acceptedWork?->contractor_id === null
                    ? null
                    : (int) $acceptedWork->contractor_id,
                'contractor_name' => $acceptedWork?->contractor_name,
                'task_id' => $entry->task_id === null ? null : (int) $entry->task_id,
                'task_name' => $entry->task_name,
                'work_type_id' => $entry->work_type_id === null ? null : (int) $entry->work_type_id,
                'work_type_name' => $entry->work_type_name,
                'billable' => (bool) $entry->is_billable,
                'accepted_work_id' => $fact->acceptedWorkId,
                'accepted_units' => $fact->acceptedUnits,
                'accepted_unit' => $fact->acceptedUnit,
                'planned_hours' => $plannedHours,
                'approved_hours' => $metrics->approvedHours,
                'hours' => $metrics->approvedHours,
                'billable_hours' => $metrics->billableHours,
                'billable_percent' => $metrics->billablePercent,
                'rate_version_id' => $resolution?->rateId,
                'rate' => $metrics->rate,
                'cost' => $metrics->cost,
                'currency' => $metrics->currency,
                'hours_variance' => $metrics->hoursVariance,
                'variance' => $metrics->hoursVariance,
                'cost_per_accepted_unit' => $metrics->costPerAcceptedUnit,
                'quality_warnings' => $metrics->qualityWarnings,
                'source_refs' => [
                    ['type' => 'time_entry', 'id' => (int) $entry->id],
                    ...($acceptedWork === null ? [] : [[
                        'type' => 'accepted_work',
                        'id' => (int) $acceptedWork->id,
                    ]]),
                    ...($resolution === null ? [] : [['type' => 'labor_rate_version', 'id' => $resolution->rateId]]),
                ],
            ];
            $this->assertMaterializedRowScope($scope, $row);
            $rows[] = $row;
        }

        $snapshot = $this->persist(
            $scope,
            $query,
            $rows,
            $this->totals($rows),
            array_values(array_unique($warnings)),
            $sourceCount,
        );
        $ownerFacts->release();

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $schema = $this->json($record->row_schema);
        $totals = $this->json($record->totals);
        if (! $context->visibility->canViewSensitive) {
            $sensitive = ['rate', 'cost', 'currency', 'cost_per_accepted_unit'];
            $schema = array_values(array_filter(
                $schema,
                static fn (array $column): bool => ! in_array($column['id'] ?? null, $sensitive, true),
            ));
            foreach ($sensitive as $field) {
                unset($totals[$field]);
            }
        }

        return new ReportResult(
            metadata: new ReportResultMetadata(
                snapshot: $snapshot,
                rowCount: (int) $record->row_count,
                generatedAt: $snapshot->generatedAt,
                staleAt: $snapshot->staleAt,
            ),
            totals: $totals,
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record),
            provenance: new ReportProvenance(
                sourceOfTruth: 'time_tracking_owner_snapshot',
                sourceRefs: $this->provenanceRefs($record),
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: null,
            ),
            rowSchema: $schema,
            capabilities: [
                'keyset' => true,
                'drill_down' => true,
                'same_snapshot_export' => true,
                'sensitive_redaction' => true,
            ],
        );
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        if ($limit < 1 || $limit > 100 || ! in_array($sort->field, self::SORTS, true)) {
            throw new InvalidArgumentException('project_labor_cost_page_invalid');
        }
        if (in_array($sort->field, ['rate', 'cost', 'cost_per_accepted_unit'], true)
            && ! $context->visibility->canViewSensitive) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $record = $this->snapshot($context, $snapshot);
        $field = match ($sort->field) {
            'hours' => 'approved_hours',
            'variance' => 'hours_variance',
            default => $sort->field,
        };
        $builder = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $position = $cursor === null
            ? null
            : $this->cursorKeyset($cursor, $snapshot, $sort, (string) $record->query_hash);
        if ($position !== null) {
            $this->applyCursor($builder, $field, $sort, $position);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $builder->orderByRaw("CASE WHEN {$field} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy($field, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit)->values();
        $rows = $records->map(
            fn (object $row): array => $this->visibleRow($this->json($row->row_payload), $context),
        )->all();
        $totals = $this->json($record->totals);
        if (! $context->visibility->canViewSensitive) {
            unset($totals['cost'], $totals['currency']);
        }

        return new ReportPage(
            rows: $rows,
            totals: $totals,
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record),
            nextCursor: null,
            limit: $limit,
            hasMore: $hasMore,
            sort: $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        if ($chunkSize < 1 || $chunkSize > 5000 || ! in_array($sort->field, self::SORTS, true)) {
            throw new InvalidArgumentException('project_labor_cost_chunk_invalid');
        }

        if (in_array($sort->field, ['rate', 'cost', 'cost_per_accepted_unit'], true)
            && ! $context->visibility->canViewSensitive) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $record = $this->snapshot($context, $snapshot);
        $queryHash = (string) $record->query_hash;
        $field = match ($sort->field) {
            'hours' => 'approved_hours',
            'variance' => 'hours_variance',
            default => $sort->field,
        };
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $position = null;
        do {
            $builder = $this->connection->table('project_labor_cost_snapshot_rows')
                ->where('organization_id', $context->scope->organizationId)
                ->where('snapshot_id', $snapshot->id);
            if ($position !== null) {
                $this->applyCursor($builder, $field, $sort, $position);
            }
            $records = $builder->orderByRaw("CASE WHEN {$field} IS NULL THEN 1 ELSE 0 END ASC")
                ->orderBy($field, $direction)
                ->orderBy('row_key', $direction)
                ->limit($chunkSize)
                ->get();
            foreach ($records as $row) {
                yield [
                    'query_hash' => $queryHash,
                    'row_key' => (string) $row->row_key,
                    'snapshot_id' => $snapshot->id,
                    'source_hash' => $snapshot->sourceHash->value,
                    'values' => $this->visibleRow($this->json($row->row_payload), $context),
                ];
            }
            $last = $records->last();
            $position = $last === null
                ? null
                : new ReportCursorKeyset($last->{$field}, (string) $last->row_key);
        } while ($records->count() === $chunkSize);
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $row = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $input->cell->rowKey)
            ->first();
        if ($row === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $payload = $this->visibleRow($this->json($row->row_payload), $context);
        $sourceRows = array_map(
            static fn (array $ref, int $index): array => [
                'row_key' => hash('sha256', $input->cell->rowKey.'|'.$index),
                'source_type' => $ref['type'],
                'source_id' => $ref['id'],
            ],
            $payload['source_refs'],
            array_keys($payload['source_refs']),
        );
        $offset = $this->drillDownOffset($input->cursor, $input->cell->rowKey);
        $nextOffset = $offset + $input->limit;

        return new ReportDrillDownResult(
            array_slice($sourceRows, $offset, $input->limit),
            $nextOffset < count($sourceRows)
                ? $this->drillDownCursor($input->cell->rowKey, $nextOffset)
                : null,
            [],
        );
    }

    private function persist(
        ReportScope $scope,
        ReportQuery $query,
        array $rows,
        array $totals,
        array $warnings,
        int $sourceCount,
    ): ReportSnapshotRef {
        $id = (string) Str::ulid();
        $generatedAt = new DateTimeImmutable;
        $staleAt = $generatedAt->add(new DateInterval('P1D'));
        [$periodFrom, $periodTo] = $this->period($query);
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        $managementPnlEligible = $this->managementPnlEligible($scope, $query);
        $sourceManifest = $this->sourceManifest($rows, $sourceCount);
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'organization_id' => $scope->organizationId,
            'query_hash' => $query->queryHash->value,
            'rows' => $rows,
            'schema_version' => self::SCHEMA_VERSION,
        ])));
        $quality = $warnings === [] && count($rows) === $sourceCount ? 'complete' : 'partial';
        $schema = array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'row_key', 'work_date', 'employee_name', 'project_name', 'contractor_name', 'task_name', 'work_type_name',
                'planned_hours', 'hours', 'billable_hours', 'billable_percent',
                'accepted_work_id', 'accepted_units', 'accepted_unit', 'rate', 'cost', 'currency',
                'variance', 'cost_per_accepted_unit', 'quality_warnings', 'drill',
            ],
        );
        $this->connection->transaction(function () use (
            $id,
            $scope,
            $query,
            $sourceHash,
            $generatedAt,
            $staleAt,
            $totals,
            $schema,
            $warnings,
            $rows,
            $sourceCount,
            $quality,
            $sourceManifest,
            $scopeHash,
            $periodFrom,
            $periodTo,
            $managementPnlEligible,
        ): void {
            $timestamp = $generatedAt->format('Y-m-d H:i:sP');
            $this->connection->table('project_labor_cost_report_snapshots')->insert([
                'id' => $id,
                'organization_id' => $scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'scope_hash' => $scopeHash,
                'source_hash' => $sourceHash->value,
                'as_of' => $query->asOf->format('Y-m-d H:i:sP'),
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'management_pnl_eligible' => $managementPnlEligible,
                'formula_version' => self::FORMULA_VERSION,
                'source_schema_version' => self::SCHEMA_VERSION,
                'freshness_status' => 'fresh',
                'quality_status' => $quality,
                'reconciliation_status' => count($rows) === $sourceCount ? 'matched' : 'mismatch',
                'totals' => CanonicalJson::encode($totals),
                'row_schema' => CanonicalJson::encode($schema),
                'warnings' => CanonicalJson::encode($warnings),
                'source_refs' => CanonicalJson::encode($sourceManifest),
                'row_count' => count($rows),
                'generated_at' => $timestamp,
                'stale_at' => $staleAt->format('Y-m-d H:i:sP'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->connection->table('project_labor_cost_snapshot_rows')->insert(array_map(
                    static function (array $row) use ($id, $scope): array {
                        $payload = $row;
                        unset($row['hours'], $row['variance']);
                        $row['organization_id'] = $scope->organizationId;
                        $row['snapshot_id'] = $id;
                        $row['quality_warnings'] = CanonicalJson::encode($row['quality_warnings']);
                        $row['source_refs'] = CanonicalJson::encode($row['source_refs']);
                        $row['row_payload'] = CanonicalJson::encode($payload);

                        return $row;
                    },
                    $chunk,
                ));
            }
        });

        return new ReportSnapshotRef(
            kind: 'project_labor_cost',
            id: $id,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: self::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: array_column($sourceManifest, 'watermark', 'snapshot_id'),
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function sourceManifest(array $rows, int $sourceCount): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $rowHash = hash('sha256', CanonicalJson::encode($row));
            foreach ($row['source_refs'] as $ref) {
                $type = (string) $ref['type'];
                $byType[$type][(int) $ref['id']][$rowHash] = $rowHash;
            }
        }
        if ($byType === []) {
            $byType['time_entry'] = [];
        }
        ksort($byType, SORT_STRING);

        return array_map(static function (string $type, array $sources) use ($sourceCount): array {
            $ids = array_keys($sources);
            sort($ids, SORT_NUMERIC);
            $identities = array_map(static function (int $id) use ($sources): array {
                $rowHashes = array_values($sources[$id]);
                sort($rowHashes, SORT_STRING);

                return ['id' => $id, 'row_hashes' => $rowHashes];
            }, $ids);

            return [
                'source' => $type,
                'snapshot_kind' => 'owner_rows',
                'snapshot_id' => 'rows_'.$type,
                'schema_version' => 'v1',
                'watermark' => 'max_id_'.($ids === [] ? 0 : max($ids)),
                'row_count' => $type === 'time_entry' && $ids === [] ? $sourceCount : count($ids),
                'hash' => hash('sha256', CanonicalJson::encode($identities)),
            ];
        }, array_keys($byType), array_values($byType));
    }

    private function provenanceRefs(object $record): array
    {
        return array_map(
            static fn (array $ref): ReportSourceRef => new ReportSourceRef(
                source: (string) $ref['source'],
                snapshotKind: (string) $ref['snapshot_kind'],
                snapshotId: (string) $ref['snapshot_id'],
                schemaVersion: (string) $ref['schema_version'],
                watermark: (string) $ref['watermark'],
                rowCount: (int) $ref['row_count'],
                hash: new Sha256Hash((string) $ref['hash']),
            ),
            $this->json($record->source_refs),
        );
    }

    private function drillDownOffset(?string $cursor, string $rowKey): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^(0|[1-9][0-9]*)\.([a-f0-9]{64})$/D', $cursor, $matches) !== 1
            || ! hash_equals(hash('sha256', $rowKey.'|'.$matches[1]), $matches[2])) {
            throw new InvalidArgumentException('project_labor_cost_drill_down_cursor_invalid');
        }

        return (int) $matches[1];
    }

    private function drillDownCursor(string $rowKey, int $offset): string
    {
        return $offset.'.'.hash('sha256', $rowKey.'|'.$offset);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): object
    {
        $record = $this->connection->table('project_labor_cost_report_snapshots')
            ->where('organization_id', $context->scope->organizationId)
            ->where('id', $snapshot->id)
            ->where('source_hash', $snapshot->materializedSourceHash->value)
            ->first();
        if ($record === null || $snapshot->scope->organizationId !== $context->scope->organizationId) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if ($snapshot->kind !== 'project_labor_cost') {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $record;
    }

    private function quality(object $record): ReportQuality
    {
        $warningCodes = $this->json($record->warnings);
        $sourceRefs = $this->json($record->source_refs);
        $timeEntryRef = collect($sourceRefs)->first(
            static fn (array $ref): bool => ($ref['source'] ?? null) === 'time_entry',
        );
        $sourceCount = (int) ($timeEntryRef['row_count'] ?? 0);

        return new ReportQuality(
            status: ReportQualityStatus::from((string) $record->quality_status),
            coverage: new ReportCoverage(
                numerator: (string) $record->row_count,
                denominator: (string) $sourceCount,
                ratio: $sourceCount === 0
                    ? null
                    : number_format((int) $record->row_count / $sourceCount, 8, '.', ''),
            ),
            warnings: array_map(
                static fn (string $code): ReportWarning => new ReportWarning(
                    $code,
                    ReportWarningSeverity::WARNING,
                    null,
                    (int) $record->row_count,
                ),
                $warningCodes,
            ),
            unmatchedCount: max($sourceCount - (int) $record->row_count, 0),
            reconciliation: ReportReconciliationStatus::from((string) $record->reconciliation_status),
            unknownMetrics: in_array('MISSING_EFFECTIVE_RATE', $warningCodes, true) ? ['labor_cost'] : [],
            excludedSources: [],
        );
    }

    private function visibleRow(array $row, ReportExecutionContext $context): array
    {
        if ($context->scope->projectIds !== []
            && ! in_array((int) $row['project_id'], $context->scope->projectIds, true)) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $this->assertScopedResource(
            $context->scope,
            'project',
            (int) $row['project_id'],
            (int) $row['project_id'],
        );
        foreach ($row['source_refs'] ?? [] as $ref) {
            if (is_array($ref) && isset($ref['type'], $ref['id'])) {
                $this->assertScopedResource(
                    $context->scope,
                    (string) $ref['type'],
                    (int) $ref['id'],
                    (int) $row['project_id'],
                );
            }
        }
        if (($row['contractor_id'] ?? null) !== null) {
            $this->assertScopedResource(
                $context->scope,
                'contractor',
                (int) $row['contractor_id'],
                (int) $row['project_id'],
            );
        }
        if (! $context->visibility->canViewSensitive) {
            unset(
                $row['rate_version_id'],
                $row['rate'],
                $row['cost'],
                $row['currency'],
                $row['hours_variance'],
                $row['cost_per_accepted_unit'],
            );
            $row['source_refs'] = array_values(array_filter(
                $row['source_refs'] ?? [],
                static fn (array $ref): bool => ($ref['type'] ?? null) !== 'labor_rate_version',
            ));
        }

        return $row;
    }

    private function acceptedWorkForEntry(Collection $acceptedWorks, object $entry): ?object
    {
        $key = implode(':', [
            $entry->user_id,
            $entry->project_id,
            $entry->task_id ?? 'none',
            $entry->work_type_id,
            $entry->work_date,
        ]);
        $matches = $acceptedWorks->get($key, collect())->unique('id')->values();
        if ($matches->count() > 1) {
            throw new DomainException('LABOR_ACCEPTED_WORK_AMBIGUOUS');
        }

        return $matches->first();
    }

    private function effectiveRate(
        Collection $ratesByEmployee,
        int $employeeId,
        DateTimeImmutable $date,
    ): ?EffectiveLaborRateResolution {
        $workDate = $date->format('Y-m-d');
        $matches = $ratesByEmployee->get($employeeId, collect())->filter(
            static fn (object $rate): bool => (string) $rate->valid_from <= $workDate
                && ($rate->valid_to_exclusive === null || $workDate < (string) $rate->valid_to_exclusive),
        )->unique(
            static fn (object $rate): string => implode(':', [
                $rate->amount,
                $rate->currency ?? 'none',
                $rate->rate_type,
                $rate->valid_from,
                $rate->valid_to_exclusive ?? 'open',
                $rate->version,
            ]),
        )->values();
        if ($matches->count() > 1) {
            throw new DomainException('LABOR_RATE_OVERLAP');
        }
        $rate = $matches->first();
        if ($rate === null) {
            return null;
        }
        $fact = new EffectiveLaborRateFact(
            rateId: (int) $rate->id,
            organizationId: (int) $rate->organization_id,
            employeeId: (int) $rate->employee_id,
            amount: (string) $rate->amount,
            currency: $rate->currency === null ? null : (string) $rate->currency,
            rateType: (string) $rate->rate_type,
            validFrom: new DateTimeImmutable((string) $rate->valid_from),
            validToExclusive: $rate->valid_to_exclusive === null
                ? null
                : new DateTimeImmutable((string) $rate->valid_to_exclusive),
            sourceVersion: (int) $rate->version,
        );

        return new EffectiveLaborRateResolution(
            rateId: $fact->rateId,
            amount: $fact->amount,
            currency: $fact->currency,
            rateType: $fact->rateType,
            sourceVersion: $fact->sourceVersion,
            quality: $fact->currency === null ? 'missing_currency' : 'complete',
        );
    }

    private function plannedHours(object $entry): ?string
    {
        if ($entry->planned_work_hours === null || $entry->planned_duration_days === null) {
            return null;
        }
        $days = (int) $entry->planned_duration_days;
        if ($days < 1) {
            throw new DomainException('LABOR_PLANNED_HOURS_INVALID');
        }

        return (string) BigDecimal::of((string) $entry->planned_work_hours)
            ->dividedBy($days, 8, RoundingMode::HalfUp);
    }

    private function assertOrganizationIds(
        string $table,
        ReportScope $scope,
        array $ids,
    ): void {
        if ($ids === []) {
            return;
        }
        $found = $this->connection->table($table)
            ->where('organization_id', $scope->organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        sort($found, SORT_NUMERIC);
        $expected = $ids;
        sort($expected, SORT_NUMERIC);
        if ($found !== $expected) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
    }

    private function assertMaterializedRowScope(ReportScope $scope, array $row): void
    {
        $projectId = (int) $row['project_id'];
        if ($scope->projectIds !== [] && ! in_array($projectId, $scope->projectIds, true)) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        foreach ($row['source_refs'] as $ref) {
            $this->assertScopedResource($scope, (string) $ref['type'], (int) $ref['id'], $projectId);
        }
        foreach ([
            'project' => $row['project_id'],
            'employee' => $row['employee_id'],
            'contractor' => $row['contractor_id'],
            'task' => $row['task_id'],
            'work_type' => $row['work_type_id'],
        ] as $kind => $id) {
            if ($id !== null) {
                $this->assertScopedResource($scope, $kind, (int) $id, $projectId);
            }
        }
    }

    private function authorizedIds(ReportScope $scope, string $kind, array $requested): array
    {
        $allowed = array_values(array_unique(array_map(
            static fn (object $resource): int => $resource->id,
            array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === $kind,
            ),
        )));
        sort($allowed, SORT_NUMERIC);
        if ($allowed === []) {
            return $requested;
        }
        if ($requested === []) {
            return $allowed;
        }
        if (array_diff($requested, $allowed) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $requested;
    }

    private function materializeTemporalOwnerFacts(
        ReportScope $scope,
        ReportQuery $query,
        array $tables,
    ): TemporalOwnerFactLease {
        return (new TemporalOwnerFactMaterializer($this->connection))->materializeExactState(
            $scope,
            $query->asOf,
            $tables,
            'TIME_TRACKING_HISTORICAL_SOURCE_UNAVAILABLE',
        );
    }

    private function managementPnlEligible(ReportScope $scope, ReportQuery $query): bool
    {
        foreach (['employee_ids', 'task_ids', 'work_type_ids', 'contractor_ids'] as $filter) {
            if (($query->filters->values[$filter] ?? []) !== []) {
                return false;
            }
        }
        if (($query->filters->values['billable'] ?? null) !== null) {
            return false;
        }
        $statuses = $query->filters->values['statuses'] ?? [];
        if ($statuses !== [] && $statuses !== ['approved']) {
            return false;
        }
        $requestedProject = (int) ($query->filters->values['project_id'] ?? 0);
        $scopeProjects = $scope->projectIds;
        $resourceProjects = array_values(array_unique(array_map(
            static fn (object $resource): int => $resource->id,
            array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            ),
        )));
        if ($resourceProjects !== []) {
            $scopeProjects = $scopeProjects === []
                ? $resourceProjects
                : array_values(array_intersect($scopeProjects, $resourceProjects));
        }
        sort($scopeProjects, SORT_NUMERIC);

        return $scopeProjects === [] || $scopeProjects === [$requestedProject];
    }

    private function assertScopedResource(
        ReportScope $scope,
        string $kind,
        int $id,
        ?int $projectId,
    ): void {
        $restricted = array_values(array_filter(
            $scope->resources,
            static fn (object $resource): bool => $resource->kind === $kind,
        ));
        if ($restricted === []) {
            return;
        }
        foreach ($restricted as $resource) {
            if ($resource->id === $id
                && ($resource->projectId === null
                    || ($projectId !== null && $resource->projectId === $projectId))) {
                return;
            }
        }

        throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
    }

    private function ids(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('project_labor_cost_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function strings(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('project_labor_cost_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function projectIds(ReportScope $scope, ReportQuery $query): array
    {
        $projectId = $query->filters->values['project_id'] ?? null;
        if (! is_int($projectId) && ! (is_string($projectId) && ctype_digit($projectId))) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        $requested = [(int) $projectId];
        $resourceIds = array_values(array_unique(array_map(
            static fn (object $resource): int => $resource->id,
            array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            ),
        )));
        $allowed = $scope->projectIds;
        if ($resourceIds !== []) {
            $allowed = $allowed === [] ? $resourceIds : array_values(array_intersect($allowed, $resourceIds));
        }
        $restricted = $scope->projectIds !== [] || $resourceIds !== [];
        if ($restricted && $allowed === []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if ($requested === []) {
            $this->assertOrganizationIds('projects', $scope, $allowed);

            return $allowed;
        }
        if ($restricted && array_diff($requested, $allowed) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $this->assertOrganizationIds('projects', $scope, $requested);

        return $requested;
    }

    private function period(ReportQuery $query): array
    {
        $from = $query->filters->values['period_from'] ?? null;
        $to = $query->filters->values['period_to'] ?? null;
        if (! is_string($from) || ! is_string($to) || $from > $to || $to > $query->asOf->format('Y-m-d')) {
            throw new InvalidArgumentException('project_labor_cost_period_invalid');
        }

        return [$from, $to];
    }

    private function totals(array $rows): array
    {
        $hours = BigDecimal::zero();
        $billable = BigDecimal::zero();
        $costs = [];
        foreach ($rows as $row) {
            $hours = $hours->plus($row['approved_hours']);
            $billable = $billable->plus($row['billable_hours']);
            if ($row['cost'] !== null && $row['currency'] !== null) {
                $costs[$row['currency']] ??= BigDecimal::zero();
                $costs[$row['currency']] = $costs[$row['currency']]->plus($row['cost']);
            }
        }

        return [
            'hours' => (string) $hours->toScale(2, RoundingMode::HalfUp),
            'billable_hours' => (string) $billable->toScale(2, RoundingMode::HalfUp),
            'cost' => array_map(
                static fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp),
                $costs,
            ),
            'currency' => array_keys($costs),
        ];
    }

    private function cursorKeyset(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        string $queryHash,
    ): ReportCursorKeyset {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->queryHash->value !== $queryHash
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new InvalidArgumentException('project_labor_cost_cursor_invalid');
        }

        return $cursor->keyset;
    }

    private function applyCursor(
        Builder $builder,
        string $field,
        ReportWindowSort $sort,
        ReportCursorKeyset $position,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($position->lastSortValue === null) {
            $builder->whereNull($field)->where('row_key', $operator, $position->lastStableRowKey);

            return;
        }
        $builder->where(static function (Builder $nested) use ($field, $operator, $position): void {
            $nested->where($field, $operator, $position->lastSortValue)
                ->orWhere(static function (Builder $same) use ($field, $operator, $position): void {
                    $same->where($field, $position->lastSortValue)
                        ->where('row_key', $operator, $position->lastStableRowKey);
                })
                ->orWhereNull($field);
        });
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        return $decoded;
    }
}
