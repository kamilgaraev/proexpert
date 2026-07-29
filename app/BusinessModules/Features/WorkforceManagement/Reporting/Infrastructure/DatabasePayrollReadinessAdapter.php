<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure;

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
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollCalculationVersion;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
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

final readonly class DatabasePayrollReadinessAdapter implements PayrollReadinessDatabasePort
{
    private const FORMULA_VERSION = 'payroll-readiness.v1';

    private const SCHEMA_VERSION = 'workforce-payroll-calculation.v1';

    private const SORTS = [
        'period_start',
        'employee_name',
        'project_name',
        'source_type',
        'hours',
        'amount',
        'severity',
        'issue_code',
        'status',
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private PayrollReadinessFormula $formula,
    ) {
    }

    public function buildVersion(int $organizationId, int $periodId, int $actorId): PayrollCalculationVersion
    {
        return $this->connection->transaction(function () use ($organizationId, $periodId, $actorId): PayrollCalculationVersion {
            $this->assertActor($organizationId, $actorId);
            $period = $this->connection->table('workforce_payroll_periods')
                ->where('organization_id', $organizationId)
                ->where('id', $periodId)
                ->lockForUpdate()
                ->first();
            if ($period === null || $period->status === 'locked') {
                throw new DomainException('PAYROLL_PERIOD_NOT_BUILDABLE');
            }

            $sourceRows = $this->sourceRows($organizationId, $periodId);
            if ($sourceRows->isEmpty()) {
                throw new DomainException('PAYROLL_SOURCE_EMPTY');
            }

            $sourceHash = $this->sourceHash($sourceRows);
            $current = $this->connection->table('workforce_payroll_calculation_versions')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $periodId)
                ->orderByDesc('version')
                ->first();
            if ($current !== null
                && $current->status === 'built'
                && hash_equals((string) $current->source_hash, $sourceHash)) {
                return $this->versionDto($current);
            }

            $now = now();
            $versionId = $this->connection->table('workforce_payroll_calculation_versions')->insertGetId([
                'organization_id' => $organizationId,
                'payroll_period_id' => $periodId,
                'version' => ((int) ($current->version ?? 0)) + 1,
                'status' => 'built',
                'source_hash' => $sourceHash,
                'formula_version' => self::FORMULA_VERSION,
                'source_row_count' => $sourceRows->count(),
                'blocking_count' => 0,
                'warning_count' => 0,
                'built_by_user_id' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($sourceRows->chunk(500) as $chunk) {
                $this->connection->table('workforce_payroll_calculation_source_rows')->insert(
                    $chunk->map(fn (object $row): array => [
                        'organization_id' => $organizationId,
                        'calculation_version_id' => $versionId,
                        'source_row_id' => (int) $row->id,
                        'employee_id' => (int) $row->employee_id,
                        'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                        'work_date' => (string) $row->work_date,
                        'source_type' => (string) $row->source_type,
                        'hours' => (string) $row->hours,
                        'amount' => (string) $row->amount,
                        'currency' => $this->sourceCurrency($row),
                        'source_refs' => CanonicalJson::encode($this->sourceRefs($row)),
                        'row_hash' => $this->rowHash($row),
                    ])->all(),
                );
            }

            return $this->versionById($organizationId, $versionId);
        });
    }

    public function validateVersion(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return $this->connection->transaction(
            function () use ($organizationId, $calculationVersionId, $actorId): PayrollCalculationVersion {
                $this->assertActor($organizationId, $actorId);
                $version = $this->versionRecord($organizationId, $calculationVersionId, true);
                if ($version->status === 'locked') {
                    throw new DomainException('PAYROLL_CALCULATION_VERSION_LOCKED');
                }
                if ($version->status === 'validated') {
                    return $this->versionDto($version);
                }
                $this->assertCurrentVersion($version);
                if (!hash_equals(
                    (string) $version->source_hash,
                    $this->sourceHash($this->sourceRows($organizationId, (int) $version->payroll_period_id)),
                )) {
                    throw new DomainException('PAYROLL_CALCULATION_SOURCE_CHANGED');
                }

                $this->connection->table('workforce_payroll_calculation_issues')
                    ->where('organization_id', $organizationId)
                    ->where('calculation_version_id', $calculationVersionId)
                    ->delete();
                $issues = $this->connection->table('workforce_payroll_validation_issues')
                    ->where('organization_id', $organizationId)
                    ->where('payroll_period_id', $version->payroll_period_id)
                    ->whereNull('resolved_at')
                    ->orderBy('id')
                    ->get();
                foreach ($issues->chunk(500) as $chunk) {
                    $this->connection->table('workforce_payroll_calculation_issues')->insert(
                        $chunk->map(fn (object $issue): array => [
                            'organization_id' => $organizationId,
                            'calculation_version_id' => $calculationVersionId,
                            'source_issue_id' => (int) $issue->id,
                            'severity' => (string) $issue->severity,
                            'issue_code' => (string) $issue->issue_code,
                            'employee_id' => $issue->employee_id === null ? null : (int) $issue->employee_id,
                            'project_id' => $issue->project_id === null ? null : (int) $issue->project_id,
                            'audit_ref' => CanonicalJson::encode([
                                'entity_type' => (string) $issue->entity_type,
                                'entity_id' => $issue->entity_id === null ? null : (int) $issue->entity_id,
                            ]),
                            'row_hash' => hash('sha256', CanonicalJson::encode([
                                'id' => (int) $issue->id,
                                'severity' => (string) $issue->severity,
                                'issue_code' => (string) $issue->issue_code,
                                'employee_id' => $issue->employee_id === null ? null : (int) $issue->employee_id,
                                'project_id' => $issue->project_id === null ? null : (int) $issue->project_id,
                            ])),
                        ])->all(),
                    );
                }

                $blockingCount = $issues->where('severity', 'blocking')->count();
                $warningCount = $issues->where('severity', 'warning')->count();
                $this->connection->table('workforce_payroll_calculation_versions')
                    ->where('organization_id', $organizationId)
                    ->where('id', $calculationVersionId)
                    ->update([
                        'status' => 'validated',
                        'blocking_count' => $blockingCount,
                        'warning_count' => $warningCount,
                        'validated_by_user_id' => $actorId,
                        'validated_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $this->versionById($organizationId, $calculationVersionId);
            },
        );
    }

    public function lockVersion(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return $this->connection->transaction(
            function () use ($organizationId, $calculationVersionId, $actorId): PayrollCalculationVersion {
                $this->assertActor($organizationId, $actorId);
                $version = $this->versionRecord($organizationId, $calculationVersionId, true);
                if ($version->status === 'locked') {
                    return $this->versionDto($version);
                }
                if ($version->status !== 'validated' || (int) $version->blocking_count > 0) {
                    throw new DomainException('PAYROLL_CALCULATION_VERSION_NOT_LOCKABLE');
                }
                $this->assertCurrentVersion($version);
                $periodStatus = $this->connection->table('workforce_payroll_periods')
                    ->where('organization_id', $organizationId)
                    ->where('id', $version->payroll_period_id)
                    ->value('status');
                if ($periodStatus !== 'locked') {
                    throw new DomainException('PAYROLL_PERIOD_NOT_LOCKED');
                }

                $this->connection->table('workforce_payroll_calculation_versions')
                    ->where('organization_id', $organizationId)
                    ->where('id', $calculationVersionId)
                    ->update([
                        'status' => 'locked',
                        'locked_by_user_id' => $actorId,
                        'locked_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $this->versionById($organizationId, $calculationVersionId);
            },
        );
    }

    public function currentVersion(int $organizationId, int $periodId): ?PayrollCalculationVersion
    {
        $record = $this->connection->table('workforce_payroll_calculation_versions')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderByDesc('version')
            ->first();

        return $record === null ? null : $this->versionDto($record);
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->organizationId !== $query->scope->organizationId) {
            throw new InvalidArgumentException('payroll_readiness_scope_invalid');
        }
        $periodIds = $query->filters->values['payroll_period_ids'] ?? null;
        if (!is_array($periodIds) || !array_is_list($periodIds) || $periodIds === []) {
            throw new InvalidArgumentException('payroll_readiness_periods_invalid');
        }
        $periodIds = array_values(array_unique(array_map(static function (mixed $id): int {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('payroll_readiness_periods_invalid');
            }

            return $id;
        }, $periodIds)));
        $projectIds = $this->projectIds($scope, $query);
        $employeeIds = $this->ids($query, 'employee_ids');
        $issueCodes = $this->strings($query, 'issue_codes');
        $severities = $this->strings($query, 'severities');
        $statuses = $this->strings($query, 'statuses');
        $sourceTypes = $this->strings($query, 'source_types');

        $versions = $this->connection->table('workforce_payroll_calculation_versions as version')
            ->join('workforce_payroll_periods as period', 'period.id', '=', 'version.payroll_period_id')
            ->where('version.organization_id', $scope->organizationId)
            ->whereIn('version.payroll_period_id', $periodIds)
            ->whereIn('version.status', ['validated', 'locked'])
            ->whereRaw(
                "version.version = (
                    SELECT MAX(v2.version)
                    FROM workforce_payroll_calculation_versions v2
                    WHERE v2.organization_id = version.organization_id
                      AND v2.payroll_period_id = version.payroll_period_id
                      AND v2.status IN ('validated', 'locked')
                )",
            )
            ->get([
                'version.*',
                'period.period_start',
                'period.period_end',
                'period.project_id as period_project_id',
            ]);
        if ($versions->count() !== count($periodIds)) {
            throw new DomainException('PAYROLL_READINESS_VALIDATED_VERSION_REQUIRED');
        }
        foreach ($versions as $version) {
            if ($scope->projectIds !== []
                && ($version->period_project_id === null
                    || !in_array((int) $version->period_project_id, $scope->projectIds, true))) {
                throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
            }
        }

        $rows = [];
        foreach ($versions as $version) {
            $issues = $this->issuesForVersion($scope->organizationId, (int) $version->id);
            $sourceRows = $this->connection->table('workforce_payroll_calculation_source_rows as source')
                ->join('workforce_employees as employee', 'employee.id', '=', 'source.employee_id')
                ->leftJoin('projects as project', 'project.id', '=', 'source.project_id')
                ->where('source.organization_id', $scope->organizationId)
                ->where('source.calculation_version_id', $version->id)
                ->when(
                    $projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('source.project_id', $projectIds),
                )
                ->when(
                    $employeeIds !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('source.employee_id', $employeeIds),
                )
                ->when(
                    $sourceTypes !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('source.source_type', $sourceTypes),
                )
                ->orderBy('source.id')
                ->get([
                    'source.*',
                    'employee.first_name',
                    'employee.last_name',
                    'employee.middle_name',
                    'project.name as project_name',
                ]);
            foreach ($sourceRows as $source) {
                $issue = $this->dominantIssue(
                    $issues,
                    (int) $source->employee_id,
                    $source->project_id === null ? null : (int) $source->project_id,
                );
                $row = [
                    'row_key' => hash('sha256', $version->id.'|'.$source->source_row_id),
                    'payroll_period_id' => (int) $version->payroll_period_id,
                    'period_start' => (string) $version->period_start,
                    'period_end' => (string) $version->period_end,
                    'calculation_version_id' => (int) $version->id,
                    'calculation_version' => (int) $version->version,
                    'employee_id' => (int) $source->employee_id,
                    'employee_name' => trim(implode(' ', array_filter([
                        $source->last_name,
                        $source->first_name,
                        $source->middle_name,
                    ]))),
                    'project_id' => $source->project_id === null ? null : (int) $source->project_id,
                    'project_name' => $source->project_name,
                    'source_type' => (string) $source->source_type,
                    'source_row_id' => (int) $source->source_row_id,
                    'hours' => (string) $source->hours,
                    'amount' => (string) $source->amount,
                    'currency' => $source->currency,
                    'issue_id' => $issue?->source_issue_id === null ? null : (int) $issue->source_issue_id,
                    'issue_code' => $issue?->issue_code,
                    'severity' => $issue?->severity,
                    'status' => $issue?->severity === 'blocking'
                        ? 'blocked'
                        : ($issue === null ? 'ready' : 'warning'),
                    'source_refs' => $this->json($source->source_refs),
                    'audit_refs' => $issue === null ? [] : [$this->json($issue->audit_ref)],
                ];
                if (($issueCodes === [] || in_array((string) $row['issue_code'], $issueCodes, true))
                    && ($severities === [] || in_array((string) $row['severity'], $severities, true))
                    && ($statuses === [] || in_array($row['status'], $statuses, true))) {
                    $rows[] = $row;
                }
            }
        }

        return $this->persist($scope, $query, $rows, $versions);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);

        return new ReportResult(
            metadata: new ReportResultMetadata(
                snapshot: $snapshot,
                rowCount: (int) $record->row_count,
                generatedAt: $snapshot->generatedAt,
                staleAt: $snapshot->staleAt,
            ),
            totals: $this->json($record->totals),
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record),
            provenance: new ReportProvenance(
                sourceOfTruth: 'locked_payroll_calculation',
                sourceRefs: [
                    new ReportSourceRef(
                        source: 'payroll_calculation',
                        snapshotKind: 'payroll_readiness',
                        snapshotId: 'payroll_readiness_v1',
                        schemaVersion: 'v1',
                        watermark: 'locked',
                        rowCount: (int) $record->row_count,
                        hash: $snapshot->sourceHash,
                    ),
                ],
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: null,
            ),
            rowSchema: $this->json($record->row_schema),
            capabilities: [
                'keyset' => true,
                'drill_down' => true,
                'same_snapshot_export' => true,
                'immutable_calculation_version' => true,
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
            throw new InvalidArgumentException('payroll_readiness_page_invalid');
        }
        $record = $this->snapshot($context, $snapshot);
        $builder = $this->connection->table('payroll_readiness_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $position = $cursor === null ? null : $this->decodeCursor($cursor, $snapshot, $sort);
        if ($position !== null) {
            $this->applyCursor($builder, $sort, $position);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $builder->orderByRaw("CASE WHEN {$sort->field} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit)->values();
        $rows = $records->map(
            fn (object $row): array => $this->visibleRow($this->json($row->row_payload), $context),
        )->all();
        $last = $records->last();

        return new ReportPage(
            rows: $rows,
            totals: $this->json($record->totals),
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record),
            nextCursor: $hasMore && $last !== null
                ? $this->encodeCursor($last->{$sort->field}, (string) $last->row_key)
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
            throw new InvalidArgumentException('payroll_readiness_chunk_invalid');
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
        $row = $this->connection->table('payroll_readiness_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $request->token)
            ->first();
        if ($row === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $payload = $this->visibleRow($this->json($row->row_payload), $context);
        $refs = array_merge($payload['source_refs'], $payload['audit_refs'] ?? []);
        $rows = array_map(
            static fn (array $ref, int $index): array => [
                'row_key' => hash('sha256', $request->token.'|'.$index),
                'source_type' => (string) ($ref['type'] ?? $ref['entity_type'] ?? 'payroll'),
                'source_id' => (int) ($ref['id'] ?? $ref['entity_id'] ?? 0),
            ],
            $refs,
            array_keys($refs),
        );

        return new ReportDrillDownResult($rows, null, []);
    }

    private function persist(
        ReportScope $scope,
        ReportQuery $query,
        array $rows,
        Collection $versions,
    ): ReportSnapshotRef {
        $id = (string) Str::ulid();
        $generatedAt = new DateTimeImmutable();
        $staleAt = $generatedAt->add(new DateInterval('P1D'));
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'organization_id' => $scope->organizationId,
            'query_hash' => $query->queryHash->value,
            'calculation_versions' => $versions->map(
                static fn (object $version): array => [
                    'id' => (int) $version->id,
                    'source_hash' => (string) $version->source_hash,
                ],
            )->all(),
            'rows' => $rows,
            'schema_version' => self::SCHEMA_VERSION,
        ])));
        $totals = $this->totals($rows, $versions);
        $warningCodes = array_values(array_unique(array_filter(array_column($rows, 'issue_code'))));
        $quality = $totals['readiness_state'] === 'ready' ? 'complete' : 'partial';
        $reconciliation = (int) $totals['source_rows'] === count($rows) ? 'matched' : 'mismatch';
        $schema = array_map(
            static fn (string $column): array => ['id' => $column],
            [
                'period_start',
                'period_end',
                'calculation_version',
                'employee_name',
                'project_name',
                'source_type',
                'hours',
                'amount',
                'currency',
                'issue_code',
                'severity',
                'status',
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
            $warningCodes,
            $quality,
            $reconciliation,
            $versions,
            $rows,
        ): void {
            $timestamp = $generatedAt->format('Y-m-d H:i:sP');
            $this->connection->table('workforce_report_snapshots')->insert([
                'id' => $id,
                'organization_id' => $scope->organizationId,
                'report_code' => 'payroll_readiness',
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'formula_version' => self::FORMULA_VERSION,
                'source_schema_version' => self::SCHEMA_VERSION,
                'freshness_status' => 'fresh',
                'quality_status' => $quality,
                'reconciliation_status' => $reconciliation,
                'totals' => CanonicalJson::encode($totals),
                'row_schema' => CanonicalJson::encode($schema),
                'warnings' => CanonicalJson::encode($warningCodes),
                'source_refs' => CanonicalJson::encode($versions->map(
                    static fn (object $version): array => [
                        'calculation_version_id' => (int) $version->id,
                        'payroll_period_id' => (int) $version->payroll_period_id,
                        'source_hash' => (string) $version->source_hash,
                        'row_count' => (int) $version->source_row_count,
                    ],
                )->all()),
                'row_count' => count($rows),
                'generated_at' => $timestamp,
                'stale_at' => $staleAt->format('Y-m-d H:i:sP'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->connection->table('payroll_readiness_snapshot_rows')->insert(array_map(
                    static function (array $row) use ($id, $scope): array {
                        $payload = $row;
                        $row['organization_id'] = $scope->organizationId;
                        $row['snapshot_id'] = $id;
                        $row['source_refs'] = CanonicalJson::encode($row['source_refs']);
                        $row['audit_refs'] = CanonicalJson::encode($row['audit_refs']);
                        $row['row_payload'] = CanonicalJson::encode($payload);

                        return $row;
                    },
                    $chunk,
                ));
            }
        });

        return new ReportSnapshotRef(
            kind: 'payroll_readiness',
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

    private function totals(array $rows, Collection $versions): array
    {
        $hours = BigDecimal::zero();
        $amounts = [];
        $unassignedHours = BigDecimal::zero();
        $unratedHours = BigDecimal::zero();
        foreach ($rows as $row) {
            $hours = $hours->plus($row['hours']);
            $currency = $row['currency'] ?? 'UNSPECIFIED';
            $amounts[$currency] = ($amounts[$currency] ?? BigDecimal::zero())->plus($row['amount']);
            $issueCode = strtolower((string) ($row['issue_code'] ?? ''));
            if (str_contains($issueCode, 'assignment') || str_contains($issueCode, 'project')) {
                $unassignedHours = $unassignedHours->plus($row['hours']);
            }
            if (str_contains($issueCode, 'rate') || str_contains($issueCode, 'tariff')) {
                $unratedHours = $unratedHours->plus($row['hours']);
            }
        }
        $sourceCount = count($rows);
        $blockingCount = $versions->sum(static fn (object $version): int => (int) $version->blocking_count);
        $warningCount = $versions->sum(static fn (object $version): int => (int) $version->warning_count);
        $metrics = $this->formula->calculate(
            sourceRowCount: $sourceCount,
            coveredSourceRowCount: count($rows),
            blockingIssueCount: $blockingCount,
            warningCount: $warningCount,
            sourceHours: (string) $hours,
            sourceAmounts: array_map(
                static fn (BigDecimal $amount): string => (string) $amount,
                $amounts,
            ),
            unassignedHours: (string) $unassignedHours,
            unratedHours: (string) $unratedHours,
        );

        return [
            'source_rows' => $metrics->sourceRowCount,
            'source_hours' => $metrics->sourceHours,
            'source_amounts' => $metrics->sourceAmounts,
            'coverage_percent' => $metrics->coveragePercent,
            'blocking_issues' => $metrics->blockingIssueCount,
            'warnings' => $metrics->warningCount,
            'issue_rate' => $metrics->issueRate,
            'unassigned_hours' => $metrics->unassignedHours,
            'unrated_hours' => $metrics->unratedHours,
            'ready' => $metrics->ready,
            'readiness_state' => $metrics->readinessState,
        ];
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): object
    {
        $record = $this->connection->table('workforce_report_snapshots')
            ->where('organization_id', $context->scope->organizationId)
            ->where('id', $snapshot->id)
            ->where('report_code', 'payroll_readiness')
            ->where('source_hash', $snapshot->sourceHash->value)
            ->first();
        if ($record === null || $snapshot->scope->organizationId !== $context->scope->organizationId) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if ($snapshot->kind !== 'payroll_readiness') {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $record;
    }

    private function quality(object $record): ReportQuality
    {
        $totals = $this->json($record->totals);
        $denominator = (int) ($totals['source_rows'] ?? 0);
        $numerator = (int) $record->row_count;
        $warnings = $this->json($record->warnings);

        return new ReportQuality(
            status: ReportQualityStatus::from((string) $record->quality_status),
            coverage: new ReportCoverage(
                numerator: (string) $numerator,
                denominator: (string) $denominator,
                ratio: $denominator === 0
                    ? null
                    : (string) BigDecimal::of($numerator)
                        ->dividedBy($denominator, 8, RoundingMode::HalfUp),
            ),
            warnings: array_map(
                static fn (string $code): ReportWarning => new ReportWarning(
                    $code,
                    ReportWarningSeverity::WARNING,
                    null,
                    $numerator,
                ),
                $warnings,
            ),
            unmatchedCount: max($denominator - $numerator, 0),
            reconciliation: ReportReconciliationStatus::from((string) $record->reconciliation_status),
            unknownMetrics: [],
            excludedSources: [],
        );
    }

    private function visibleRow(array $row, ReportExecutionContext $context): array
    {
        if ($context->scope->projectIds !== []
            && ($row['project_id'] === null
                || !in_array((int) $row['project_id'], $context->scope->projectIds, true))) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if (!$context->visibility->canViewAudit) {
            unset($row['audit_refs'], $row['calculation_version_id'], $row['issue_id']);
        }

        return $row;
    }

    private function ids(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('payroll_readiness_filter_invalid');
        }
        foreach ($values as $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('payroll_readiness_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function strings(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('payroll_readiness_filter_invalid');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('payroll_readiness_filter_invalid');
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

    private function sourceRows(int $organizationId, int $periodId): Collection
    {
        return $this->connection->table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('id')
            ->get();
    }

    private function sourceHash(Collection $rows): string
    {
        return hash('sha256', CanonicalJson::encode($rows->map(
            fn (object $row): array => [
                'id' => (int) $row->id,
                'row_hash' => $this->rowHash($row),
            ],
        )->all()));
    }

    private function rowHash(object $row): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => (int) $row->id,
            'employee_id' => (int) $row->employee_id,
            'project_id' => $row->project_id === null ? null : (int) $row->project_id,
            'work_date' => (string) $row->work_date,
            'source_type' => (string) $row->source_type,
            'hours' => (string) $row->hours,
            'amount' => (string) $row->amount,
            'currency' => $this->sourceCurrency($row),
            'source_refs' => $this->sourceRefs($row),
        ]));
    }

    private function sourceRefs(object $row): array
    {
        $refs = [['type' => 'payroll_source_row', 'id' => (int) $row->id]];
        foreach (['timesheet_entry_id', 'work_order_id', 'work_order_line_id'] as $column) {
            if ($row->{$column} !== null) {
                $refs[] = ['type' => $column, 'id' => (int) $row->{$column}];
            }
        }

        return $refs;
    }

    private function sourceCurrency(object $row): ?string
    {
        $payload = $row->payload === null ? [] : $this->json($row->payload);
        $currency = $payload['currency'] ?? null;
        if ($currency === null) {
            return null;
        }
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('PAYROLL_SOURCE_CURRENCY_INVALID');
        }

        return $currency;
    }

    private function issuesForVersion(int $organizationId, int $versionId): Collection
    {
        return $this->connection->table('workforce_payroll_calculation_issues')
            ->where('organization_id', $organizationId)
            ->where('calculation_version_id', $versionId)
            ->orderByRaw("CASE WHEN severity = 'blocking' THEN 0 ELSE 1 END")
            ->orderBy('source_issue_id')
            ->get();
    }

    private function dominantIssue(Collection $issues, int $employeeId, ?int $projectId): ?object
    {
        return $issues->first(static fn (object $issue): bool => (
            $issue->employee_id === null || (int) $issue->employee_id === $employeeId
        ) && (
            $issue->project_id === null || (int) $issue->project_id === $projectId
        ));
    }

    private function versionById(int $organizationId, int $versionId): PayrollCalculationVersion
    {
        return $this->versionDto($this->versionRecord($organizationId, $versionId));
    }

    private function assertActor(int $organizationId, int $actorId): void
    {
        if ($actorId < 1 || !$this->connection->table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->exists()) {
            throw new DomainException('PAYROLL_CALCULATION_ACTOR_NOT_FOUND');
        }
    }

    private function assertCurrentVersion(object $version): void
    {
        $currentId = $this->connection->table('workforce_payroll_calculation_versions')
            ->where('organization_id', $version->organization_id)
            ->where('payroll_period_id', $version->payroll_period_id)
            ->orderByDesc('version')
            ->value('id');
        if ((int) $currentId !== (int) $version->id) {
            throw new DomainException('PAYROLL_CALCULATION_VERSION_SUPERSEDED');
        }
    }

    private function versionRecord(int $organizationId, int $versionId, bool $lock = false): object
    {
        $query = $this->connection->table('workforce_payroll_calculation_versions')
            ->where('organization_id', $organizationId)
            ->where('id', $versionId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if ($record === null) {
            throw new DomainException('PAYROLL_CALCULATION_VERSION_NOT_FOUND');
        }

        return $record;
    }

    private function versionDto(object $record): PayrollCalculationVersion
    {
        return new PayrollCalculationVersion(
            id: (int) $record->id,
            organizationId: (int) $record->organization_id,
            payrollPeriodId: (int) $record->payroll_period_id,
            version: (int) $record->version,
            status: (string) $record->status,
            sourceHash: (string) $record->source_hash,
            formulaVersion: (string) $record->formula_version,
            sourceRowCount: (int) $record->source_row_count,
            blockingCount: (int) $record->blocking_count,
            warningCount: (int) $record->warning_count,
            validatedAt: $record->validated_at === null
                ? null
                : new DateTimeImmutable((string) $record->validated_at),
            lockedAt: $record->locked_at === null
                ? null
                : new DateTimeImmutable((string) $record->locked_at),
        );
    }

    private function decodeCursor(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): array {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new InvalidArgumentException('payroll_readiness_cursor_invalid');
        }
        $decoded = base64_decode(strtr($cursor->token, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)
            || !is_bool($payload['is_null'] ?? null)
            || (!($payload['is_null'] ?? true) && !is_string($payload['value'] ?? null))
            || !is_string($payload['row_key'] ?? null)) {
            throw new InvalidArgumentException('payroll_readiness_cursor_invalid');
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
        ReportWindowSort $sort,
        array $position,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($position['is_null']) {
            $builder->whereNull($sort->field)->where('row_key', $operator, $position['row_key']);

            return;
        }
        $builder->where(static function (Builder $nested) use ($sort, $operator, $position): void {
            $nested->where($sort->field, $operator, $position['value'])
                ->orWhere(static function (Builder $same) use ($sort, $operator, $position): void {
                    $same->where($sort->field, $position['value'])
                        ->where('row_key', $operator, $position['row_key']);
                })
                ->orWhereNull($sort->field);
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
