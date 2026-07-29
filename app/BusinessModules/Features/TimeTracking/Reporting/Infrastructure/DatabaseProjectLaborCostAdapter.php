<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
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
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use App\BusinessModules\Features\TimeTracking\Reporting\EffectiveLaborRateResolver;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DatabaseProjectLaborCostAdapter implements ProjectLaborCostDatabasePort
{
    private const FORMULA_VERSION = 'labor-cost.v1';

    private const SCHEMA_VERSION = 'project-labor-cost-source.v1';

    private const SORTS = [
        'work_date', 'employee_name', 'project_name', 'task_name',
        'hours', 'rate', 'cost', 'variance',
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private ProjectLaborCostFormula $formula,
    ) {
    }

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
        [$periodFrom, $periodTo] = $this->period($query);
        $projectIds = $this->projectIds($scope, $query);
        $employeeIds = $this->ids($query, 'employee_ids');
        $taskIds = $this->ids($query, 'task_ids');
        $workTypeIds = $this->ids($query, 'work_type_ids');
        $contractorIds = $this->ids($query, 'contractor_ids');
        $statuses = $this->strings($query, 'statuses');
        $billable = $query->filters->values['billable'] ?? null;
        if ($billable !== null && !is_bool($billable)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        $sourceQuery = $this->connection->table('time_entries as source_entry')
            ->when(
                $employeeIds !== [],
                static fn (Builder $builder): Builder => $builder
                    ->join('workforce_employees as source_employee', function ($join): void {
                        $join->on('source_employee.user_id', '=', 'source_entry.user_id')
                            ->on('source_employee.organization_id', '=', 'source_entry.organization_id');
                    })
                    ->whereIn('source_employee.id', $employeeIds),
            )
            ->where('source_entry.organization_id', $scope->organizationId)
            ->where('source_entry.status', 'approved')
            ->whereNull('source_entry.deleted_at')
            ->whereBetween('source_entry.work_date', [$periodFrom, $periodTo])
            ->when(
                $projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('source_entry.project_id', $projectIds),
            )
            ->when(
                $taskIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('source_entry.task_id', $taskIds),
            )
            ->when(
                $workTypeIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('source_entry.work_type_id', $workTypeIds),
            )
            ->when(
                $billable !== null,
                static fn (Builder $builder): Builder => $builder->where('source_entry.is_billable', $billable),
            )
            ->when(
                $contractorIds !== [] || ($statuses !== [] && !in_array('approved', $statuses, true)),
                static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
            );
        $sourceCount = (int) $sourceQuery->count('source_entry.id');
        $entries = $this->connection->table('time_entries as entry')
            ->join('workforce_employees as employee', function ($join): void {
                $join->on('employee.user_id', '=', 'entry.user_id')
                    ->on('employee.organization_id', '=', 'entry.organization_id');
            })
            ->join('projects as project', 'project.id', '=', 'entry.project_id')
            ->leftJoin('schedule_tasks as task', 'task.id', '=', 'entry.task_id')
            ->leftJoin('work_types as work_type', 'work_type.id', '=', 'entry.work_type_id')
            ->where('entry.organization_id', $scope->organizationId)
            ->where('entry.status', 'approved')
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
                $contractorIds !== [] || ($statuses !== [] && !in_array('approved', $statuses, true)),
                static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
            )
            ->select([
                'entry.id',
                'entry.organization_id',
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
                'work_type.name as work_type_name',
            ])
            ->orderBy('entry.work_date')
            ->orderBy('entry.id')
            ->get();
        $resolver = new EffectiveLaborRateResolver($this);
        $rows = [];
        $warnings = [];
        foreach ($entries as $entry) {
            $fact = new ProjectLaborEntryFact(
                timeEntryId: (int) $entry->id,
                organizationId: (int) $entry->organization_id,
                employeeId: (int) $entry->employee_id,
                projectId: (int) $entry->project_id,
                taskId: $entry->task_id === null ? null : (int) $entry->task_id,
                workTypeId: $entry->work_type_id === null ? null : (int) $entry->work_type_id,
                acceptedWorkId: null,
                workDate: new DateTimeImmutable((string) $entry->work_date),
                status: (string) $entry->status,
                hours: (string) $entry->hours_worked,
                billable: (bool) $entry->is_billable,
                acceptedUnits: null,
                acceptedUnit: null,
            );
            $resolution = $resolver->atDate(
                $scope->organizationId,
                (int) $entry->employee_id,
                $fact->workDate,
            );
            $metrics = $this->formula->calculate($fact, $resolution, null);
            if ($metrics === null) {
                continue;
            }
            $warnings = array_merge($warnings, $metrics->qualityWarnings);
            $rows[] = [
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
                'task_id' => $entry->task_id === null ? null : (int) $entry->task_id,
                'task_name' => $entry->task_name,
                'work_type_id' => $entry->work_type_id === null ? null : (int) $entry->work_type_id,
                'work_type_name' => $entry->work_type_name,
                'billable' => (bool) $entry->is_billable,
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
                    ...($resolution === null ? [] : [['type' => 'labor_rate_version', 'id' => $resolution->rateId]]),
                ],
            ];
        }
        if ($entries->count() !== $sourceCount) {
            $warnings[] = 'UNLINKED_WORKFORCE_EMPLOYEE';
        }

        return $this->persist(
            $scope,
            $query,
            $rows,
            $this->totals($rows),
            array_values(array_unique($warnings)),
            $sourceCount,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $schema = $this->json($record->row_schema);
        $totals = $this->json($record->totals);
        if (!$context->visibility->canViewSensitive) {
            $sensitive = ['rate', 'cost', 'currency', 'variance', 'cost_per_accepted_unit'];
            $schema = array_values(array_filter(
                $schema,
                static fn (array $column): bool => !in_array($column['id'] ?? null, $sensitive, true),
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
                sourceRefs: [
                    new ReportSourceRef(
                        source: 'project_labor_cost',
                        snapshotKind: 'labor_cost_snapshot',
                        snapshotId: 'labor_cost_snapshot_v1',
                        schemaVersion: 'v1',
                        watermark: 'materialized',
                        rowCount: (int) $record->row_count,
                        hash: $snapshot->sourceHash,
                    ),
                ],
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
        if ($limit < 1 || $limit > 100 || !in_array($sort->field, self::SORTS, true)) {
            throw new InvalidArgumentException('project_labor_cost_page_invalid');
        }
        if (in_array($sort->field, ['rate', 'cost'], true) && !$context->visibility->canViewSensitive) {
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
        $position = $cursor === null ? null : $this->decodeCursor($cursor, $snapshot, $sort);
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
        $last = $records->last();
        $totals = $this->json($record->totals);
        if (!$context->visibility->canViewSensitive) {
            unset($totals['cost'], $totals['currency']);
        }

        return new ReportPage(
            rows: $rows,
            totals: $totals,
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record),
            nextCursor: $hasMore && $last !== null
                ? $this->encodeCursor($last->{$field}, (string) $last->row_key)
                : null,
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
        if ($chunkSize < 1 || $chunkSize > 100) {
            throw new InvalidArgumentException('project_labor_cost_chunk_invalid');
        }
        $cursor = null;
        do {
            $page = $this->page($context, $snapshot, $sort, $cursor, $chunkSize);
            foreach ($page->rows as $row) {
                yield $row;
            }
            $cursor = $page->nextCursor === null
                ? null
                : new ReportCursor(
                    token: $page->nextCursor,
                    runId: '01J00000000000000000000000',
                    queryHash: new Sha256Hash((string) $this->snapshot($context, $snapshot)->query_hash),
                    sourceHash: $snapshot->sourceHash,
                    sort: $sort,
                    expiresAt: new DateTimeImmutable('+1 hour'),
                );
        } while ($page->hasMore);
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $row = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $request->token)
            ->first();
        if ($row === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $payload = $this->visibleRow($this->json($row->row_payload), $context);
        $sourceRows = array_map(
            static fn (array $ref, int $index): array => [
                'row_key' => hash('sha256', $request->token.'|'.$index),
                'source_type' => $ref['type'],
                'source_id' => $ref['id'],
            ],
            $payload['source_refs'],
            array_keys($payload['source_refs']),
        );

        return new ReportDrillDownResult($sourceRows, null, []);
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
        $generatedAt = new DateTimeImmutable();
        $staleAt = $generatedAt->add(new DateInterval('P1D'));
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
                'work_date', 'employee_name', 'project_name', 'task_name', 'hours',
                'billable_hours', 'billable_percent', 'rate', 'cost', 'currency',
                'variance', 'cost_per_accepted_unit',
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
        ): void {
            $timestamp = $generatedAt->format('Y-m-d H:i:sP');
            $this->connection->table('project_labor_cost_report_snapshots')->insert([
                'id' => $id,
                'organization_id' => $scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'formula_version' => self::FORMULA_VERSION,
                'source_schema_version' => self::SCHEMA_VERSION,
                'freshness_status' => 'fresh',
                'quality_status' => $quality,
                'reconciliation_status' => count($rows) === $sourceCount ? 'matched' : 'mismatch',
                'totals' => CanonicalJson::encode($totals),
                'row_schema' => CanonicalJson::encode($schema),
                'warnings' => CanonicalJson::encode($warnings),
                'source_refs' => CanonicalJson::encode([
                    ['source' => 'approved_time_entries', 'row_count' => $sourceCount],
                ]),
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
            watermarks: ['source_schema_version' => self::SCHEMA_VERSION],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): object
    {
        $record = $this->connection->table('project_labor_cost_report_snapshots')
            ->where('organization_id', $context->scope->organizationId)
            ->where('id', $snapshot->id)
            ->where('source_hash', $snapshot->sourceHash->value)
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
        $sourceCount = (int) ($sourceRefs[0]['row_count'] ?? 0);

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
            && !in_array((int) $row['project_id'], $context->scope->projectIds, true)) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if (!$context->visibility->canViewSensitive) {
            unset(
                $row['rate_version_id'],
                $row['rate'],
                $row['cost'],
                $row['currency'],
                $row['variance'],
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

    private function ids(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        foreach ($values as $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('project_labor_cost_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function strings(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('project_labor_cost_filter_invalid');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('project_labor_cost_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function projectIds(ReportScope $scope, ReportQuery $query): array
    {
        $requested = $this->ids($query, 'project_ids');
        if ($requested === []) {
            return $scope->projectIds;
        }
        if ($scope->projectIds !== [] && array_diff($requested, $scope->projectIds) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $requested;
    }

    private function period(ReportQuery $query): array
    {
        $from = $query->filters->values['period_from'] ?? null;
        $to = $query->filters->values['period_to'] ?? null;
        if (!is_string($from) || !is_string($to) || $from > $to || $to > $query->asOf->format('Y-m-d')) {
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

    private function decodeCursor(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): array {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new InvalidArgumentException('project_labor_cost_cursor_invalid');
        }
        $decoded = base64_decode(strtr($cursor->token, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)
            || !is_bool($payload['is_null'] ?? null)
            || (!($payload['is_null'] ?? true) && !is_string($payload['value'] ?? null))
            || !is_string($payload['row_key'] ?? null)) {
            throw new InvalidArgumentException('project_labor_cost_cursor_invalid');
        }

        return $payload;
    }

    private function encodeCursor(mixed $value, string $rowKey): string
    {
        return rtrim(strtr(base64_encode(CanonicalJson::encode([
            'is_null' => $value === null,
            'row_key' => $rowKey,
            'value' => $value === null ? null : (string) $value,
        ])), '+/', '-_'), '=');
    }

    private function applyCursor(
        Builder $builder,
        string $field,
        ReportWindowSort $sort,
        array $position,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($position['is_null']) {
            $builder->whereNull($field)->where('row_key', $operator, $position['row_key']);

            return;
        }
        $builder->where(static function (Builder $nested) use ($field, $operator, $position): void {
            $nested->where($field, $operator, $position['value'])
                ->orWhere(static function (Builder $same) use ($field, $operator, $position): void {
                    $same->where($field, $position['value'])
                        ->where('row_key', $operator, $position['row_key']);
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
        if (!is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        return $decoded;
    }
}
