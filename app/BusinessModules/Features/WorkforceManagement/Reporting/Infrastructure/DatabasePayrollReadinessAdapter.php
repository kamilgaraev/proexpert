<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure;

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
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollCalculationVersion;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollIssueMatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollVersionTransitionResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
        private PayrollSourceRateFormula $sourceRateFormula,
    ) {}

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

            $rawSourceRows = $this->sourceRows($organizationId, $periodId);
            if ($rawSourceRows->isEmpty()) {
                throw new DomainException('PAYROLL_SOURCE_EMPTY');
            }
            $sourceRows = $this->canonicalSourceRows($organizationId, $rawSourceRows);

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
            $this->recordTransition($organizationId, $versionId, 'built', $actorId, $now);

            foreach ($sourceRows->chunk(500) as $chunk) {
                $this->connection->table('workforce_payroll_calculation_source_rows')->insert(
                    $chunk->map(static fn (array $row): array => [
                        'organization_id' => $organizationId,
                        'calculation_version_id' => $versionId,
                        'source_row_id' => $row['source_row_id'],
                        'employee_id' => $row['employee_id'],
                        'project_id' => $row['project_id'],
                        'work_date' => $row['work_date'],
                        'source_type' => $row['source_type'],
                        'hours' => $row['hours'],
                        'rate_version_id' => $row['rate_version_id'],
                        'rate_type' => $row['rate_type'],
                        'rate' => $row['rate'],
                        'amount' => $row['amount'],
                        'currency' => $row['currency'],
                        'source_refs' => CanonicalJson::encode($row['source_refs']),
                        'row_hash' => $row['row_hash'],
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
                if (! hash_equals(
                    (string) $version->source_hash,
                    $this->sourceHash($this->canonicalSourceRows(
                        $organizationId,
                        $this->sourceRows($organizationId, (int) $version->payroll_period_id),
                    )),
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
                $this->assertIssueOwnership($organizationId, $issues);
                $issueSourceRows = $this->issueSourceRowMap(
                    $organizationId,
                    $calculationVersionId,
                );
                foreach ($issues->chunk(500) as $chunk) {
                    $this->connection->table('workforce_payroll_calculation_issues')->insert(
                        $chunk->map(fn (object $issue): array => [
                            'organization_id' => $organizationId,
                            'calculation_version_id' => $calculationVersionId,
                            'source_issue_id' => (int) $issue->id,
                            'source_row_id' => $this->issueSourceRowId($issue, $issueSourceRows),
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
                                'source_row_id' => $this->issueSourceRowId($issue, $issueSourceRows),
                                'severity' => (string) $issue->severity,
                                'issue_code' => (string) $issue->issue_code,
                                'employee_id' => $issue->employee_id === null ? null : (int) $issue->employee_id,
                                'project_id' => $issue->project_id === null ? null : (int) $issue->project_id,
                            ])),
                        ])->all(),
                    );
                }
                $missingRates = $this->connection->table('workforce_payroll_calculation_source_rows')
                    ->where('organization_id', $organizationId)
                    ->where('calculation_version_id', $calculationVersionId)
                    ->where(static function (Builder $builder): void {
                        $builder->whereNull('rate_version_id')
                            ->orWhereNull('rate')
                            ->orWhereNull('currency')
                            ->orWhereNull('amount');
                    })
                    ->orderBy('source_row_id')
                    ->get();
                foreach ($missingRates->chunk(500) as $chunk) {
                    $this->connection->table('workforce_payroll_calculation_issues')->insert(
                        $chunk->map(static function (object $source): array {
                            $auditRef = [
                                'entity_type' => 'payroll_source_row',
                                'entity_id' => (int) $source->source_row_id,
                            ];

                            return [
                                'organization_id' => (int) $source->organization_id,
                                'calculation_version_id' => (int) $source->calculation_version_id,
                                'source_issue_id' => null,
                                'source_row_id' => (int) $source->source_row_id,
                                'severity' => 'blocking',
                                'issue_code' => 'PAYROLL_EFFECTIVE_RATE_MISSING',
                                'employee_id' => (int) $source->employee_id,
                                'project_id' => $source->project_id === null ? null : (int) $source->project_id,
                                'audit_ref' => CanonicalJson::encode($auditRef),
                                'row_hash' => hash('sha256', CanonicalJson::encode([
                                    'issue_code' => 'PAYROLL_EFFECTIVE_RATE_MISSING',
                                    'source_row_id' => (int) $source->source_row_id,
                                    'employee_id' => (int) $source->employee_id,
                                    'project_id' => $source->project_id === null
                                        ? null
                                        : (int) $source->project_id,
                                ])),
                            ];
                        })->all(),
                    );
                }

                $blockingCount = $issues->where('severity', 'blocking')->count() + $missingRates->count();
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
                $this->recordTransition(
                    $organizationId,
                    $calculationVersionId,
                    'validated',
                    $actorId,
                    now(),
                );

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
                if (! hash_equals(
                    (string) $version->source_hash,
                    $this->sourceHash($this->canonicalSourceRows(
                        $organizationId,
                        $this->sourceRows($organizationId, (int) $version->payroll_period_id),
                    )),
                )) {
                    throw new DomainException('PAYROLL_CALCULATION_SOURCE_CHANGED');
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
                $this->recordTransition(
                    $organizationId,
                    $calculationVersionId,
                    'locked',
                    $actorId,
                    now(),
                );

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
        if (! is_array($periodIds) || ! array_is_list($periodIds) || $periodIds === []) {
            throw new InvalidArgumentException('payroll_readiness_periods_invalid');
        }
        $periodIds = array_values(array_unique(array_map(static function (mixed $id): int {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('payroll_readiness_periods_invalid');
            }

            return $id;
        }, $periodIds)));
        $periodIds = $this->authorizedIds($scope, 'payroll_period', $periodIds);
        $projectIds = $this->projectIds($scope, $query);
        $employeeIds = $this->authorizedIds($scope, 'employee', $this->ids($query, 'employee_ids'));
        $issueCodes = $this->strings($query, 'issue_codes');
        $severities = $this->strings($query, 'severities');
        $statuses = $this->strings($query, 'statuses');
        $sourceTypes = $this->strings($query, 'source_types');
        $this->assertOrganizationIds('workforce_payroll_periods', $scope, $periodIds);
        $this->assertOrganizationIds('workforce_employees', $scope, $employeeIds);
        $this->assertOrganizationIds('projects', $scope, $projectIds);

        $candidateVersions = $this->connection->table('workforce_payroll_calculation_versions as version')
            ->join('workforce_payroll_periods as period', static function (JoinClause $join): void {
                $join->on('period.id', '=', 'version.payroll_period_id')
                    ->on('period.organization_id', '=', 'version.organization_id');
            })
            ->where('version.organization_id', $scope->organizationId)
            ->whereIn('version.payroll_period_id', $periodIds)
            ->where('version.created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->orderBy('version.payroll_period_id')
            ->orderByDesc('version.version')
            ->get([
                'version.*',
                'period.period_start',
                'period.period_end',
                'period.project_id as period_project_id',
            ]);
        $candidateVersionIds = $candidateVersions
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $transitions = $candidateVersionIds === []
            ? collect()
            : $this->connection->table('workforce_payroll_calculation_transitions')
                ->where('organization_id', $scope->organizationId)
                ->whereIn('calculation_version_id', $candidateVersionIds)
                ->where('transitioned_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->orderBy('transitioned_at')
                ->orderBy('id')
                ->get()
                ->groupBy(static fn (object $transition): int => (int) $transition->calculation_version_id);
        $transitionResolver = new PayrollVersionTransitionResolver;
        $versions = $candidateVersions
            ->filter(static function (object $version) use (
                $transitions,
                $transitionResolver,
                $query,
            ): bool {
                $status = $transitionResolver->at(
                    $transitions->get((int) $version->id, collect()),
                    $query->asOf,
                );
                if (! in_array($status, ['validated', 'locked'], true)) {
                    return false;
                }
                $version->status = $status;

                return true;
            })
            ->groupBy(static fn (object $version): int => (int) $version->payroll_period_id)
            ->map(static fn (Collection $periodVersions): object => $periodVersions
                ->sortByDesc(static fn (object $version): int => (int) $version->version)
                ->first())
            ->values();
        if ($versions->count() !== count($periodIds)) {
            throw new DomainException('PAYROLL_READINESS_VALIDATED_VERSION_REQUIRED');
        }
        foreach ($versions as $version) {
            if ($scope->projectIds !== []
                && ($version->period_project_id === null
                    || ! in_array((int) $version->period_project_id, $scope->projectIds, true))) {
                throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
            }
        }

        $versionIds = $versions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $versionsById = $versions->keyBy(static fn (object $version): int => (int) $version->id);
        $sourceRows = $this->connection->table('workforce_payroll_calculation_source_rows as source')
            ->join('workforce_employees as employee', static function (JoinClause $join): void {
                $join->on('employee.id', '=', 'source.employee_id')
                    ->on('employee.organization_id', '=', 'source.organization_id');
            })
            ->leftJoin('projects as project', static function (JoinClause $join): void {
                $join->on('project.id', '=', 'source.project_id')
                    ->on('project.organization_id', '=', 'source.organization_id');
            })
            ->where('source.organization_id', $scope->organizationId)
            ->whereIn('source.calculation_version_id', $versionIds)
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
            ->orderBy('source.calculation_version_id')
            ->orderBy('source.id')
            ->get([
                'source.*',
                'employee.first_name',
                'employee.last_name',
                'employee.middle_name',
                'project.name as project_name',
            ]);
        $issues = $this->connection->table('workforce_payroll_calculation_issues as issue')
            ->leftJoin('workforce_employees as employee', static function (JoinClause $join): void {
                $join->on('employee.id', '=', 'issue.employee_id')
                    ->on('employee.organization_id', '=', 'issue.organization_id');
            })
            ->leftJoin('projects as project', static function (JoinClause $join): void {
                $join->on('project.id', '=', 'issue.project_id')
                    ->on('project.organization_id', '=', 'issue.organization_id');
            })
            ->where('issue.organization_id', $scope->organizationId)
            ->whereIn('issue.calculation_version_id', $versionIds)
            ->orderBy('issue.calculation_version_id')
            ->orderByRaw("CASE WHEN issue.severity = 'blocking' THEN 0 ELSE 1 END")
            ->orderBy('issue.id')
            ->get([
                'issue.*',
                'employee.first_name',
                'employee.last_name',
                'employee.middle_name',
                'project.name as project_name',
            ]);
        $issuesByVersion = $issues->groupBy(
            static fn (object $issue): int => (int) $issue->calculation_version_id,
        );
        $issueMatcher = new PayrollIssueMatcher;

        $rows = [];
        foreach ($sourceRows as $source) {
            $version = $versionsById->get((int) $source->calculation_version_id);
            if ($version === null) {
                throw new DomainException('PAYROLL_CALCULATION_VERSION_NOT_FOUND');
            }
            $sourceIssues = $issueMatcher->forSourceRow(
                $issuesByVersion->get((int) $version->id, collect()),
                (int) $source->source_row_id,
            );
            foreach ($sourceIssues === [] ? [null] : $sourceIssues as $issue) {
                $row = [
                    'row_key' => hash(
                        'sha256',
                        'source|'.$version->id.'|'.$source->source_row_id.'|'.($issue?->id ?? 'none'),
                    ),
                    'row_type' => 'source',
                    'payroll_period_id' => (int) $version->payroll_period_id,
                    'period_start' => (string) $version->period_start,
                    'period_end' => (string) $version->period_end,
                    'calculation_version_id' => (int) $version->id,
                    'calculation_version' => (int) $version->version,
                    'employee_id' => (int) $source->employee_id,
                    'employee_name' => $this->employeeName($source),
                    'project_id' => $source->project_id === null ? null : (int) $source->project_id,
                    'project_name' => $source->project_name,
                    'source_type' => (string) $source->source_type,
                    'source_row_id' => (int) $source->source_row_id,
                    'hours' => (string) $source->hours,
                    'rate' => $source->rate,
                    'rate_type' => $source->rate_type,
                    'amount' => $source->amount,
                    'currency' => $source->currency,
                    'issue_id' => $issue === null ? null : (int) $issue->id,
                    'issue_code' => $issue?->issue_code,
                    'severity' => $issue?->severity,
                    'status' => $issue?->severity === 'blocking'
                        ? 'blocked'
                        : ($issue === null ? 'ready' : 'warning'),
                    'source_refs' => $this->json($source->source_refs),
                    'audit_refs' => $issue === null ? [] : [
                        [
                            'entity_type' => 'payroll_calculation_issue',
                            'entity_id' => (int) $issue->id,
                        ],
                        $this->json($issue->audit_ref),
                    ],
                ];
                $this->assertMaterializedRowScope($scope, $row);
                if ($this->matchesIssueFilters($row, $issueCodes, $severities, $statuses)) {
                    $rows[] = $row;
                }
            }
        }
        foreach ($issues as $issue) {
            if ($issue->source_row_id !== null
                || ($projectIds !== []
                    && ($issue->project_id === null
                        || ! in_array((int) $issue->project_id, $projectIds, true)))
                || ($employeeIds !== []
                    && ($issue->employee_id === null
                        || ! in_array((int) $issue->employee_id, $employeeIds, true)))) {
                continue;
            }
            if ($sourceTypes !== []) {
                continue;
            }
            $version = $versionsById->get((int) $issue->calculation_version_id);
            if ($version === null) {
                throw new DomainException('PAYROLL_CALCULATION_VERSION_NOT_FOUND');
            }
            $row = [
                'row_key' => hash('sha256', 'issue|'.$version->id.'|'.$issue->id),
                'row_type' => 'issue',
                'payroll_period_id' => (int) $version->payroll_period_id,
                'period_start' => (string) $version->period_start,
                'period_end' => (string) $version->period_end,
                'calculation_version_id' => (int) $version->id,
                'calculation_version' => (int) $version->version,
                'employee_id' => $issue->employee_id === null ? null : (int) $issue->employee_id,
                'employee_name' => $issue->employee_id === null ? null : $this->employeeName($issue),
                'project_id' => $issue->project_id === null ? null : (int) $issue->project_id,
                'project_name' => $issue->project_name,
                'source_type' => null,
                'source_row_id' => null,
                'hours' => null,
                'rate' => null,
                'rate_type' => null,
                'amount' => null,
                'currency' => null,
                'issue_id' => (int) $issue->id,
                'issue_code' => (string) $issue->issue_code,
                'severity' => (string) $issue->severity,
                'status' => $issue->severity === 'blocking' ? 'blocked' : 'warning',
                'source_refs' => [],
                'audit_refs' => [
                    [
                        'entity_type' => 'payroll_calculation_issue',
                        'entity_id' => (int) $issue->id,
                    ],
                    $this->json($issue->audit_ref),
                ],
            ];
            $this->assertMaterializedRowScope($scope, $row);
            if ($this->matchesIssueFilters($row, $issueCodes, $severities, $statuses)) {
                $rows[] = $row;
            }
        }

        return $this->persist($scope, $query, $rows, $versions, $sourceRows, $issues);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $totals = $this->json($record->totals);
        $schema = $this->json($record->row_schema);
        if (! $context->visibility->canViewSensitive) {
            $schema = array_values(array_filter(
                $schema,
                static fn (array $column): bool => ! in_array(
                    $column['id'] ?? null,
                    ['rate', 'rate_type', 'amount', 'currency'],
                    true,
                ),
            ));
            unset($totals['source_amounts']);
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
                sourceOfTruth: 'locked_payroll_calculation',
                sourceRefs: $this->provenanceRefs($record),
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: null,
            ),
            rowSchema: $schema,
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
        if ($limit < 1 || $limit > 100 || ! in_array($sort->field, self::SORTS, true)) {
            throw new InvalidArgumentException('payroll_readiness_page_invalid');
        }
        if ($sort->field === 'amount' && ! $context->visibility->canViewSensitive) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $record = $this->snapshot($context, $snapshot);
        $builder = $this->connection->table('payroll_readiness_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $position = $cursor === null
            ? null
            : $this->cursorKeyset($cursor, $snapshot, $sort, (string) $record->query_hash);
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
        $totals = $this->json($record->totals);
        if (! $context->visibility->canViewSensitive) {
            unset($totals['source_amounts']);
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
            throw new InvalidArgumentException('payroll_readiness_chunk_invalid');
        }
        if ($sort->field === 'amount' && ! $context->visibility->canViewSensitive) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $record = $this->snapshot($context, $snapshot);
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $position = null;
        do {
            $builder = $this->connection->table('payroll_readiness_snapshot_rows')
                ->where('organization_id', $context->scope->organizationId)
                ->where('snapshot_id', $snapshot->id);
            if ($position !== null) {
                $this->applyCursor($builder, $sort, $position);
            }
            $records = $builder->orderByRaw("CASE WHEN {$sort->field} IS NULL THEN 1 ELSE 0 END ASC")
                ->orderBy($sort->field, $direction)
                ->orderBy('row_key', $direction)
                ->limit($chunkSize)
                ->get();
            foreach ($records as $row) {
                yield [
                    'query_hash' => (string) $record->query_hash,
                    'row_key' => (string) $row->row_key,
                    'snapshot_id' => $snapshot->id,
                    'source_hash' => $snapshot->sourceHash->value,
                    'values' => $this->visibleRow($this->json($row->row_payload), $context),
                ];
            }
            $last = $records->last();
            $position = $last === null
                ? null
                : new ReportCursorKeyset($last->{$sort->field}, (string) $last->row_key);
        } while ($records->count() === $chunkSize);
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $row = $this->connection->table('payroll_readiness_snapshot_rows')
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $input->cell->rowKey)
            ->first();
        if ($row === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $payload = $this->visibleRow($this->json($row->row_payload), $context);
        $refs = array_merge($payload['source_refs'], $payload['audit_refs'] ?? []);
        $rows = array_map(
            static fn (array $ref, int $index): array => [
                'row_key' => hash('sha256', $input->cell->rowKey.'|'.$index),
                'source_type' => (string) ($ref['type'] ?? $ref['entity_type'] ?? 'payroll'),
                'source_id' => (int) ($ref['id'] ?? $ref['entity_id'] ?? 0),
            ],
            $refs,
            array_keys($refs),
        );
        $offset = $this->drillDownOffset($input->cursor, $input->cell->rowKey);
        $nextOffset = $offset + $input->limit;

        return new ReportDrillDownResult(
            array_slice($rows, $offset, $input->limit),
            $nextOffset < count($rows)
                ? $this->drillDownCursor($input->cell->rowKey, $nextOffset)
                : null,
            [],
        );
    }

    private function persist(
        ReportScope $scope,
        ReportQuery $query,
        array $rows,
        Collection $versions,
        Collection $sourceRows,
        Collection $issues,
    ): ReportSnapshotRef {
        $id = (string) Str::ulid();
        $generatedAt = new DateTimeImmutable;
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
            'calculation_source_rows' => $sourceRows->map(
                static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'row_hash' => (string) $row->row_hash,
                ],
            )->all(),
            'calculation_issues' => $issues->map(
                static fn (object $issue): array => [
                    'id' => (int) $issue->id,
                    'row_hash' => (string) $issue->row_hash,
                ],
            )->all(),
            'rows' => $rows,
            'schema_version' => self::SCHEMA_VERSION,
        ])));
        $totals = $this->totals($rows);
        $sourceManifest = $versions->map(
            static fn (object $version): array => [
                'source' => 'payroll_calculation',
                'snapshot_kind' => 'payroll_calculation_version',
                'snapshot_id' => 'calculation_'.(int) $version->id,
                'schema_version' => 'v1',
                'watermark' => 'version_'.(int) $version->version.'_'.(string) $version->status,
                'row_count' => (int) $version->source_row_count,
                'hash' => (string) $version->source_hash,
                'payroll_period_id' => (int) $version->payroll_period_id,
            ],
        )->values()->all();
        $warningCodes = array_values(array_unique(array_filter(array_merge(
            array_map(
                static fn (array $row): string => (string) ($row['issue_code'] ?? ''),
                $rows,
            ),
            (int) $totals['unrated_source_rows'] > 0 ? ['PAYROLL_EFFECTIVE_RATE_MISSING'] : [],
        ))));
        $quality = $totals['readiness_state'] === 'ready' ? 'complete' : 'partial';
        $reconciliation = (int) $totals['source_rows'] > 0
            && (int) $totals['covered_source_rows'] === (int) $totals['source_rows']
                ? 'matched'
                : 'mismatch';
        $schema = array_map(
            static fn (string $column): array => ['id' => $column],
            [
                'period_start',
                'period_end',
                'calculation_version',
                'row_type',
                'employee_name',
                'project_name',
                'source_type',
                'hours',
                'rate',
                'rate_type',
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
            $rows,
            $sourceManifest,
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
                'source_refs' => CanonicalJson::encode($sourceManifest),
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
            watermarks: array_column($sourceManifest, 'watermark', 'snapshot_id'),
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function totals(array $rows): array
    {
        $sourceRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['row_type'] === 'source',
        ));
        $issueRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['row_type'] === 'issue',
        ));
        $sourceGroups = [];
        foreach ($sourceRows as $row) {
            $identity = (int) $row['calculation_version_id'].':'.(int) $row['source_row_id'];
            $sourceGroups[$identity][] = $row;
        }
        $hours = BigDecimal::zero();
        $amounts = [];
        $unassignedHours = BigDecimal::zero();
        $unratedHours = BigDecimal::zero();
        $coveredCount = 0;
        $implicitMissingRateCount = 0;
        foreach ($sourceGroups as $group) {
            $row = $group[0];
            $rowHours = BigDecimal::of((string) $row['hours']);
            $hours = $hours->plus($rowHours);
            if ($row['amount'] !== null && $row['currency'] !== null) {
                $currency = (string) $row['currency'];
                $amounts[$currency] = ($amounts[$currency] ?? BigDecimal::zero())->plus((string) $row['amount']);
            }
            $issueCodes = array_map(
                static fn (array $issueRow): string => strtolower((string) ($issueRow['issue_code'] ?? '')),
                $group,
            );
            if (array_filter(
                $issueCodes,
                static fn (string $code): bool => str_contains($code, 'assignment')
                    || str_contains($code, 'project'),
            ) !== []) {
                $unassignedHours = $unassignedHours->plus($rowHours);
            }
            $rateMissing = $row['rate'] === null || $row['currency'] === null || $row['amount'] === null;
            if ($rateMissing || array_filter(
                $issueCodes,
                static fn (string $code): bool => str_contains($code, 'rate')
                    || str_contains($code, 'tariff'),
            ) !== []) {
                $unratedHours = $unratedHours->plus($rowHours);
            }
            $hasBlockingIssue = array_filter(
                $group,
                static fn (array $issueRow): bool => $issueRow['severity'] === 'blocking',
            ) !== [];
            if (! $hasBlockingIssue && ! $rateMissing) {
                $coveredCount++;
            }
            if ($rateMissing && ! $hasBlockingIssue) {
                $implicitMissingRateCount++;
            }
        }
        $sourceCount = count($sourceGroups);
        $blockingIssues = [];
        $warningIssues = [];
        foreach ([...$sourceRows, ...$issueRows] as $row) {
            if (! in_array($row['severity'], ['blocking', 'warning'], true)) {
                continue;
            }
            $identity = ($row['issue_id'] ?? null) === null
                ? (string) ($row['row_key'] ?? hash('sha256', CanonicalJson::encode($row)))
                : 'issue:'.(int) $row['issue_id'];
            if ($row['severity'] === 'blocking') {
                $blockingIssues[$identity] = true;
            } else {
                $warningIssues[$identity] = true;
            }
        }
        $blockingCount = count($blockingIssues) + $implicitMissingRateCount;
        $warningCount = count($warningIssues);
        $metrics = $this->formula->calculate(
            sourceRowCount: $sourceCount,
            coveredSourceRowCount: $coveredCount,
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
            'covered_source_rows' => $coveredCount,
            'unrated_source_rows' => $sourceCount - $coveredCount,
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
        $numerator = (int) ($totals['covered_source_rows'] ?? 0);
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
            throw new InvalidArgumentException('payroll_readiness_drill_down_cursor_invalid');
        }

        return (int) $matches[1];
    }

    private function drillDownCursor(string $rowKey, int $offset): string
    {
        return $offset.'.'.hash('sha256', $rowKey.'|'.$offset);
    }

    private function visibleRow(array $row, ReportExecutionContext $context): array
    {
        $this->assertProjectAccess(
            $context->scope,
            $row['project_id'] === null ? null : (int) $row['project_id'],
        );
        $this->assertScopedResource(
            $context->scope,
            'payroll_period',
            (int) $row['payroll_period_id'],
            $row['project_id'] === null ? null : (int) $row['project_id'],
        );
        if ($row['employee_id'] !== null) {
            $this->assertScopedResource(
                $context->scope,
                'employee',
                (int) $row['employee_id'],
                $row['project_id'] === null ? null : (int) $row['project_id'],
            );
        }
        if ($row['project_id'] !== null) {
            $this->assertScopedResource($context->scope, 'project', (int) $row['project_id'], (int) $row['project_id']);
        }
        foreach ($row['source_refs'] as $ref) {
            if (isset($ref['type'], $ref['id']) && is_string($ref['type']) && is_int($ref['id'])) {
                $this->assertScopedResource(
                    $context->scope,
                    $ref['type'],
                    $ref['id'],
                    $row['project_id'] === null ? null : (int) $row['project_id'],
                );
            }
        }
        if ($context->visibility->canViewAudit) {
            foreach ($row['audit_refs'] as $ref) {
                if (isset($ref['entity_type'], $ref['entity_id'])
                    && is_string($ref['entity_type'])
                    && is_int($ref['entity_id'])) {
                    $this->assertScopedResource(
                        $context->scope,
                        $ref['entity_type'],
                        $ref['entity_id'],
                        $row['project_id'] === null ? null : (int) $row['project_id'],
                    );
                }
            }
        }
        if (! $context->visibility->canViewAudit) {
            unset($row['audit_refs'], $row['calculation_version_id'], $row['issue_id']);
        }
        if (! $context->visibility->canViewSensitive) {
            unset($row['rate'], $row['rate_type'], $row['amount'], $row['currency']);
            $row['source_refs'] = array_values(array_filter(
                $row['source_refs'],
                static fn (array $ref): bool => ($ref['type'] ?? null) !== 'labor_rate_version',
            ));
        }

        return $row;
    }

    private function ids(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('payroll_readiness_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('payroll_readiness_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function employeeName(object $record): string
    {
        return trim(implode(' ', array_filter([
            $record->last_name,
            $record->first_name,
            $record->middle_name,
        ])));
    }

    private function matchesIssueFilters(
        array $row,
        array $issueCodes,
        array $severities,
        array $statuses,
    ): bool {
        return ($issueCodes === [] || in_array((string) $row['issue_code'], $issueCodes, true))
            && ($severities === [] || in_array((string) $row['severity'], $severities, true))
            && ($statuses === [] || in_array((string) $row['status'], $statuses, true));
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
        $projectId = $row['project_id'] === null ? null : (int) $row['project_id'];
        $this->assertProjectAccess($scope, $projectId);
        foreach ([
            'payroll_period' => $row['payroll_period_id'],
            'project' => $row['project_id'],
            'employee' => $row['employee_id'],
        ] as $kind => $id) {
            if ($id !== null) {
                $this->assertScopedResource($scope, $kind, (int) $id, $projectId);
            }
        }
        foreach ($row['source_refs'] as $ref) {
            $this->assertScopedResource($scope, (string) $ref['type'], (int) $ref['id'], $projectId);
        }
        foreach ($row['audit_refs'] as $ref) {
            if (($ref['entity_id'] ?? null) !== null) {
                $this->assertScopedResource(
                    $scope,
                    (string) $ref['entity_type'],
                    (int) $ref['entity_id'],
                    $projectId,
                );
            }
        }
    }

    private function assertProjectAccess(ReportScope $scope, ?int $projectId): void
    {
        $resourceProjectIds = array_map(
            static fn (object $resource): int => $resource->id,
            array_values(array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            )),
        );
        if ($projectId === null && ($scope->projectIds !== [] || $resourceProjectIds !== [])) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if ($projectId !== null
            && ($scope->projectIds !== [] && ! in_array($projectId, $scope->projectIds, true)
                || $resourceProjectIds !== [] && ! in_array($projectId, $resourceProjectIds, true))) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
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

    private function strings(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('payroll_readiness_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('payroll_readiness_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function projectIds(ReportScope $scope, ReportQuery $query): array
    {
        $requested = $this->ids($query, 'project_ids');
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

    private function sourceRows(int $organizationId, int $periodId): Collection
    {
        return $this->connection->table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('id')
            ->get();
    }

    private function canonicalSourceRows(int $organizationId, Collection $rows): Collection
    {
        $this->assertPayrollSourceOwnership($organizationId, $rows);
        $employeeIds = $rows
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $rates = $employeeIds === []
            ? collect()
            : $this->connection->table('time_tracking_labor_rate_versions')
                ->where('organization_id', $organizationId)
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->orderBy('employee_id')
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get()
                ->groupBy(static fn (object $rate): int => (int) $rate->employee_id);

        return $rows->map(function (object $row) use ($rates): array {
            $rate = $this->effectiveRate(
                $rates->get((int) $row->employee_id, collect()),
                (string) $row->work_date,
            );
            $calculation = $rate === null || $rate->currency === null
                ? null
                : $this->sourceRateFormula->calculate(
                    hours: (string) $row->hours,
                    rate: (string) $rate->amount,
                    rateType: (string) $rate->rate_type,
                    currency: (string) $rate->currency,
                );
            $sourceRefs = $this->sourceRefs($row);
            if ($rate !== null) {
                $sourceRefs[] = [
                    'type' => 'labor_rate_version',
                    'id' => (int) $rate->id,
                    'version' => (int) $rate->version,
                ];
            }
            $canonical = [
                'source_row_id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'work_date' => (string) $row->work_date,
                'source_type' => (string) $row->source_type,
                'hours' => (string) BigDecimal::of((string) $row->hours)->toScale(4, RoundingMode::Unnecessary),
                'rate_version_id' => $calculation === null ? null : (int) $rate->id,
                'rate_type' => $calculation === null ? null : (string) $rate->rate_type,
                'rate' => $calculation?->rate,
                'amount' => $calculation?->amount,
                'currency' => $calculation?->currency,
                'source_refs' => $sourceRefs,
            ];
            $canonical['row_hash'] = $this->rowHash($canonical);

            return $canonical;
        });
    }

    private function issueSourceRowMap(int $organizationId, int $calculationVersionId): array
    {
        $rows = $this->connection->table('workforce_payroll_calculation_source_rows')
            ->where('organization_id', $organizationId)
            ->where('calculation_version_id', $calculationVersionId)
            ->orderBy('id')
            ->get(['source_row_id', 'source_refs']);
        $map = [];
        foreach ($rows as $row) {
            $sourceRowId = (int) $row->source_row_id;
            $map['payroll_source_row:'.$sourceRowId][$sourceRowId] = $sourceRowId;
            foreach ($this->json($row->source_refs) as $ref) {
                if (isset($ref['type'], $ref['id'])) {
                    $map[(string) $ref['type'].':'.(int) $ref['id']][$sourceRowId] = $sourceRowId;
                }
            }
        }

        return $map;
    }

    private function issueSourceRowId(object $issue, array $sourceRowsByRef): ?int
    {
        if ($issue->entity_type === null || $issue->entity_id === null) {
            return null;
        }
        $matches = array_values(
            $sourceRowsByRef[(string) $issue->entity_type.':'.(int) $issue->entity_id] ?? [],
        );
        if (count($matches) > 1) {
            throw new DomainException('PAYROLL_VALIDATION_ISSUE_SOURCE_AMBIGUOUS');
        }

        return $matches[0] ?? null;
    }

    private function recordTransition(
        int $organizationId,
        int $calculationVersionId,
        string $status,
        int $actorId,
        mixed $transitionedAt,
    ): void {
        $timestamp = (string) $transitionedAt;
        $this->connection->table('workforce_payroll_calculation_transitions')->insert([
            'organization_id' => $organizationId,
            'calculation_version_id' => $calculationVersionId,
            'status' => $status,
            'actor_id' => $actorId,
            'transitioned_at' => $transitionedAt,
            'transition_hash' => hash('sha256', CanonicalJson::encode([
                'organization_id' => $organizationId,
                'calculation_version_id' => $calculationVersionId,
                'status' => $status,
                'actor_id' => $actorId,
                'transitioned_at' => $timestamp,
            ])),
        ]);
    }

    private function assertPayrollSourceOwnership(int $organizationId, Collection $rows): void
    {
        foreach ([
            'employee_id' => 'workforce_employees',
            'project_id' => 'projects',
            'timesheet_entry_id' => 'production_labor_timesheet_entries',
            'work_order_id' => 'production_labor_work_orders',
            'work_order_line_id' => 'production_labor_work_order_lines',
        ] as $column => $table) {
            $this->assertOwnedIds(
                $organizationId,
                $table,
                $rows->pluck($column)
                    ->filter(static fn (mixed $id): bool => $id !== null)
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            );
        }
    }

    private function effectiveRate(Collection $rates, string $workDate): ?object
    {
        $effective = $rates->filter(static fn (object $rate): bool => (string) $rate->valid_from <= $workDate
            && ($rate->valid_to_exclusive === null || (string) $rate->valid_to_exclusive > $workDate));
        if ($effective->count() > 1) {
            throw new DomainException('PAYROLL_EFFECTIVE_RATE_OVERLAP');
        }

        return $effective->first();
    }

    private function sourceHash(Collection $rows): string
    {
        return hash('sha256', CanonicalJson::encode($rows->map(
            static fn (array $row): array => [
                'id' => $row['source_row_id'],
                'row_hash' => $row['row_hash'],
            ],
        )->all()));
    }

    private function rowHash(array $row): string
    {
        unset($row['row_hash']);

        return hash('sha256', CanonicalJson::encode($row));
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

    private function assertIssueOwnership(int $organizationId, Collection $issues): void
    {
        $tables = [
            'payroll_source_row' => 'workforce_payroll_source_rows',
            'production_labor_output_entry' => 'production_labor_output_entries',
        ];
        foreach ($issues->groupBy('entity_type') as $entityType => $typedIssues) {
            if (! is_string($entityType) || ! isset($tables[$entityType])) {
                throw new DomainException('PAYROLL_VALIDATION_ISSUE_SOURCE_INVALID');
            }
            $ids = $typedIssues->pluck('entity_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $this->assertOwnedIds($organizationId, $tables[$entityType], $ids);
        }
        $this->assertOwnedIds(
            $organizationId,
            'workforce_employees',
            $issues->pluck('employee_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertOwnedIds(
            $organizationId,
            'projects',
            $issues->pluck('project_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
    }

    private function assertOwnedIds(int $organizationId, string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $found = $this->connection->table($table)
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        sort($found, SORT_NUMERIC);
        sort($ids, SORT_NUMERIC);
        if ($found !== $ids) {
            throw new DomainException('PAYROLL_VALIDATION_ISSUE_SOURCE_INVALID');
        }
    }

    private function versionById(int $organizationId, int $versionId): PayrollCalculationVersion
    {
        return $this->versionDto($this->versionRecord($organizationId, $versionId));
    }

    private function assertActor(int $organizationId, int $actorId): void
    {
        if ($actorId < 1 || ! $this->connection->table('organization_user')
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
            throw new InvalidArgumentException('payroll_readiness_cursor_invalid');
        }

        return $cursor->keyset;
    }

    private function applyCursor(
        Builder $builder,
        ReportWindowSort $sort,
        ReportCursorKeyset $position,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($position->lastSortValue === null) {
            $builder->whereNull($sort->field)->where('row_key', $operator, $position->lastStableRowKey);

            return;
        }
        $builder->where(static function (Builder $nested) use ($sort, $operator, $position): void {
            $nested->where($sort->field, $operator, $position->lastSortValue)
                ->orWhere(static function (Builder $same) use ($sort, $operator, $position): void {
                    $same->where($sort->field, $position->lastSortValue)
                        ->where('row_key', $operator, $position->lastStableRowKey);
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
        if (! is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        return $decoded;
    }
}
