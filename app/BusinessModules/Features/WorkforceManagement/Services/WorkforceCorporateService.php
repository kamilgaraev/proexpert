<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessOwnerSnapshotRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WorkforceCorporateService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly WorkforceProService $proService,
        private readonly PayrollReadinessOwnerSnapshotRecorder $payrollReadinessRecorder,
    ) {
    }

    public function listAccountingMappings(int $organizationId): Collection
    {
        return DB::table('workforce_accounting_mappings')
            ->where('organization_id', $organizationId)
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $record): array => $this->decorateAccountingMapping($organizationId, $record));
    }

    public function storeAccountingMapping(int $organizationId, array $payload): array
    {
        return DB::transaction(function () use ($organizationId, $payload): array {
            $this->lockOrganization($organizationId);
            $payload = $this->normalizeMappingPayload($payload);
            $this->assertMappingScope($organizationId, $payload);

            $id = DB::table('workforce_accounting_mappings')->insertGetId(array_merge($payload, [
                'organization_id' => $organizationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return $this->decorateAccountingMapping($organizationId, DB::table('workforce_accounting_mappings')->where('organization_id', $organizationId)->where('id', $id)->first());
        });
    }

    public function updateAccountingMapping(int $organizationId, int $mappingId, array $payload): array
    {
        return DB::transaction(function () use ($organizationId, $mappingId, $payload): array {
            $this->lockOrganization($organizationId);
            $current = $this->assertRecord('workforce_accounting_mappings', $organizationId, $mappingId);
            $merged = $this->normalizeMappingPayload(array_merge((array) $current, $payload));
            $this->assertMappingScope($organizationId, $merged);

            DB::table('workforce_accounting_mappings')
                ->where('organization_id', $organizationId)
                ->where('id', $mappingId)
                ->update(array_merge($this->normalizeMappingPayload($payload + ['scope_type' => $merged['scope_type']]), ['updated_at' => now()]));

            return $this->decorateAccountingMapping($organizationId, DB::table('workforce_accounting_mappings')->where('organization_id', $organizationId)->where('id', $mappingId)->first());
        });
    }

    public function lockPayrollPeriod(int $organizationId, int $periodId, int $userId): array
    {
        $result = DB::transaction(function () use ($organizationId, $periodId, $userId): array {
            $this->lockOrganization($organizationId);
            $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

            if ($period->status === 'locked') {
                return (array) $period;
            }

            $evaluatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->lockPayrollSnapshotRows($organizationId, $period);
            $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);
            $ownerSourceHash = $this->payrollSnapshotHash($organizationId, $period);
            $identity = PayrollReadinessPeriodIdentity::fromRecord($organizationId, $period);

            if ($period->status !== 'validated') {
                $this->payrollReadinessRecorder->recordBlocked(
                    $identity,
                    $userId,
                    $evaluatedAt,
                    $ownerSourceHash,
                    PayrollReadinessReason::PERIOD_NOT_VALIDATED,
                );

                return ['failure_message_key' => 'workforce.errors.payroll_period_not_validated'];
            }

            if (! $this->hasSourceRows($organizationId, $periodId)) {
                $this->payrollReadinessRecorder->recordBlocked(
                    $identity,
                    $userId,
                    $evaluatedAt,
                    $ownerSourceHash,
                    PayrollReadinessReason::SOURCE_EMPTY,
                );

                return ['failure_message_key' => 'workforce.errors.payroll_source_empty'];
            }

            if (! $this->isProductionSourceActual($organizationId, $period)) {
                $this->payrollReadinessRecorder->recordBlocked(
                    $identity,
                    $userId,
                    $evaluatedAt,
                    $ownerSourceHash,
                    PayrollReadinessReason::SOURCE_CHANGED,
                );

                return ['failure_message_key' => 'workforce.errors.payroll_source_changed'];
            }

            $this->proService->validatePayrollPeriod($organizationId, $periodId);
            $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

            if ($period->status !== 'validated') {
                $this->payrollReadinessRecorder->recordBlocked(
                    $identity,
                    $userId,
                    $evaluatedAt,
                    $ownerSourceHash,
                    PayrollReadinessReason::VALIDATION_BLOCKERS,
                );

                return ['failure_message_key' => 'workforce.errors.payroll_period_has_blocking_issues'];
            }

            $this->refreshAccountingIssues($organizationId, $periodId);

            if ($this->hasBlockingIssues($organizationId, $periodId)) {
                $this->payrollReadinessRecorder->recordBlocked(
                    $identity,
                    $userId,
                    $evaluatedAt,
                    $ownerSourceHash,
                    PayrollReadinessReason::ACCOUNTING_BLOCKERS,
                );

                return ['failure_message_key' => 'workforce.errors.payroll_period_has_blocking_issues'];
            }

            $sourceHash = $this->payrollSnapshotHash($organizationId, $period);
            $lockedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            DB::table('workforce_payroll_periods')
                ->where('organization_id', $organizationId)
                ->where('id', $periodId)
                ->update([
                    'status' => 'locked',
                    'locked_at' => $lockedAt,
                    'locked_by_user_id' => $userId,
                    'source_hash' => $sourceHash,
                    'updated_at' => $lockedAt,
                ]);

            $lockedPeriod = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);
            $this->payrollReadinessRecorder->recordLocked(
                PayrollReadinessPeriodIdentity::fromRecord($organizationId, $lockedPeriod),
                $userId,
                $lockedAt,
                $sourceHash,
            );

            return (array) $lockedPeriod;
        });

        if (isset($result['failure_message_key'])) {
            throw new DomainException(trans_message((string) $result['failure_message_key']));
        }

        return $result;
    }

    public function exportPackages(int $organizationId, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        $query = DB::table('workforce_export_packages as package')
            ->join('workforce_payroll_periods as period', 'period.id', '=', 'package.payroll_period_id')
            ->where('package.organization_id', $organizationId)
            ->select([
                'package.*',
                'period.period_start as payroll_period_start',
                'period.period_end as payroll_period_end',
            ])
            ->orderByDesc('package.id');

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('package.package_number', 'like', "%{$search}%")
                    ->orWhere('package.status', 'like', "%{$search}%")
                    ->orWhereRaw('CAST(period.period_start AS TEXT) ILIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(period.period_end AS TEXT) ILIKE ?', ["%{$search}%"]);
            });
        }

        return $query
            ->paginate($perPage)
            ->through(fn (object $record): array => $this->decorateExportPackage($organizationId, $record));
    }

    public function showExportPackage(int $organizationId, int $packageId): array
    {
        $package = $this->decorateExportPackage($organizationId, $this->assertRecord('workforce_export_packages', $organizationId, $packageId));
        $package['files'] = DB::table('workforce_export_package_files')
            ->where('organization_id', $organizationId)
            ->where('export_package_id', $packageId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => array_merge((array) $record, [
                'file_type_label' => trans_message("workforce.file_type_labels.{$record->file_type}"),
            ]))
            ->all();

        return $package;
    }

    public function createExportPackage(int $organizationId, int $periodId, int $userId): array
    {
        $prepared = DB::transaction(function () use ($organizationId, $periodId): array {
            $this->lockOrganization($organizationId);
            $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

            if ($period->status !== 'locked') {
                throw new DomainException(trans_message('workforce.errors.payroll_period_not_locked'));
            }

            $this->assertSourceRows($organizationId, $periodId);
            $this->lockPayrollSnapshotRows($organizationId, $period);
            $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);
            $this->refreshAccountingIssues($organizationId, $periodId);

            if ($this->hasBlockingIssues($organizationId, $periodId)) {
                return ['blocking_issues' => true];
            }

            $this->assertSourceHashActual($organizationId, $period);

            $packages = DB::table('workforce_export_packages')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $periodId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($packages->contains('status', 'accepted')) {
                throw new DomainException(trans_message('workforce.errors.export_package_accepted'));
            }

            $activePackage = $packages
                ->whereIn('status', ['created', 'sent'])
                ->sortByDesc('id')
                ->first();

            if ($activePackage) {
                return ['existing_package_id' => (int) $activePackage->id];
            }

            return [
                'rows' => $this->exportRows($organizationId, $periodId),
                'source_hash' => $this->payrollSnapshotHash($organizationId, $period),
                'package_key' => now()->format('YmdHis') . '-' . bin2hex(random_bytes(8)),
            ];
        });

        if (isset($prepared['blocking_issues'])) {
            throw new DomainException(trans_message('workforce.errors.payroll_period_has_blocking_issues'));
        }

        if (isset($prepared['existing_package_id'])) {
            return $this->showExportPackage($organizationId, $prepared['existing_package_id']);
        }

        $files = $this->buildFiles(
            $organizationId,
            $periodId,
            $prepared['package_key'],
            $prepared['rows']
        );

        try {
            $result = DB::transaction(function () use (
                $organizationId,
                $periodId,
                $userId,
                $prepared,
                $files,
            ): array {
                $this->lockOrganization($organizationId);
                $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

                if ($period->status !== 'locked') {
                    throw new DomainException(trans_message('workforce.errors.payroll_period_not_locked'));
                }

                $this->lockPayrollSnapshotRows($organizationId, $period);
                $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);
                $this->assertSourceHashActual($organizationId, $period);

                if ($this->hasBlockingIssues($organizationId, $periodId)) {
                    throw new DomainException(trans_message('workforce.errors.payroll_period_has_blocking_issues'));
                }

                $currentHash = $this->payrollSnapshotHash($organizationId, $period);

                if (!hash_equals($prepared['source_hash'], $currentHash)) {
                    throw new DomainException(trans_message('workforce.errors.payroll_source_changed'));
                }

                $packages = DB::table('workforce_export_packages')
                    ->where('organization_id', $organizationId)
                    ->where('payroll_period_id', $periodId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($packages->contains('status', 'accepted')) {
                    throw new DomainException(trans_message('workforce.errors.export_package_accepted'));
                }

                $activePackage = $packages
                    ->whereIn('status', ['created', 'sent'])
                    ->sortByDesc('id')
                    ->first();

                if ($activePackage) {
                    return ['package_id' => (int) $activePackage->id, 'files_adopted' => false];
                }

                $rejectedPackage = $packages
                    ->where('status', 'rejected')
                    ->sortByDesc('id')
                    ->first();
                $packageId = DB::table('workforce_export_packages')->insertGetId([
                    'organization_id' => $organizationId,
                    'payroll_period_id' => $periodId,
                    'supersedes_package_id' => $rejectedPackage?->id,
                    'package_number' => 'WF-' . $periodId . '-' . $prepared['package_key'],
                    'status' => 'created',
                    'source_hash' => $currentHash,
                    'created_by_user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $timestamp = now();
                $fileRows = array_map(
                    fn (array $file): array => array_merge($file, [
                        'organization_id' => $organizationId,
                        'export_package_id' => $packageId,
                        'storage_disk' => 's3',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]),
                    $files
                );

                DB::table('workforce_export_package_files')->insert($fileRows);

                return ['package_id' => $packageId, 'files_adopted' => true];
            });
        } catch (Throwable $exception) {
            $this->deleteFiles($organizationId, $files);
            throw $exception;
        }

        if (!$result['files_adopted']) {
            $this->deleteFiles($organizationId, $files);
        }

        return $this->showExportPackage($organizationId, $result['package_id']);
    }

    public function markExportPackage(int $organizationId, int $packageId, string $status, ?string $reason = null): array
    {
        return DB::transaction(function () use ($organizationId, $packageId, $status, $reason): array {
            $this->lockOrganization($organizationId);
            $package = DB::table('workforce_export_packages')
                ->where('organization_id', $organizationId)
                ->where('id', $packageId)
                ->lockForUpdate()
                ->first();

            if (!$package) {
                throw new DomainException(trans_message('workforce.errors.record_not_found'));
            }

            if ($package->status === $status) {
                return $this->showExportPackage($organizationId, $packageId);
            }

            $this->assertExportTransition((string) $package->status, $status);
            $payload = ['status' => $status, 'updated_at' => now()];

            if ($status === 'sent') {
                $payload['sent_at'] = now();
            } elseif ($status === 'accepted') {
                $payload['accepted_at'] = now();
            } elseif ($status === 'rejected') {
                $payload['rejected_at'] = now();
                $payload['rejection_reason'] = $reason;
            }

            DB::table('workforce_export_packages')
                ->where('organization_id', $organizationId)
                ->where('id', $packageId)
                ->update($payload);

            return $this->showExportPackage($organizationId, $packageId);
        });
    }

    public function exportPackageFile(int $organizationId, int $packageId, int $fileId): array
    {
        $this->assertRecord('workforce_export_packages', $organizationId, $packageId);
        $file = DB::table('workforce_export_package_files')
            ->where('organization_id', $organizationId)
            ->where('export_package_id', $packageId)
            ->where('id', $fileId)
            ->first();

        if (!$file) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        $organization = $this->organization($organizationId);

        return [
            'file_name' => $file->file_name,
            'storage_path' => $file->storage_path,
            'download_url' => $this->fileService->temporaryUrl($file->storage_path, 15, $organization),
        ];
    }

    private function buildFiles(int $organizationId, int $periodId, string $packageKey, Collection $rows): array
    {
        $organization = $this->organization($organizationId);
        $basePath = "org-{$organizationId}/workforce/payroll-exports/period-{$periodId}/package-{$packageKey}";
        $files = [
            [
                'file_type' => 'source_json',
                'file_name' => 'payroll-source.json',
                'content' => json_encode($rows->values()->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
            [
                'file_type' => 'source_csv',
                'file_name' => 'payroll-source.csv',
                'content' => $this->csv($rows, [
                    'period_start',
                    'period_end',
                    'personnel_number',
                    'external_payroll_ref',
                    'employee_name',
                    'department_code',
                    'position_code',
                    'project_id',
                    'project_name',
                    'work_order_number',
                    'work_date',
                    'source_type',
                    'hours',
                    'amount',
                    'cost_category_id',
                    'accounting_account',
                    'source_document',
                ]),
            ],
            [
                'file_type' => 'summary_csv',
                'file_name' => 'payroll-summary.csv',
                'content' => $this->summaryCsv($rows),
            ],
        ];

        $storedFiles = [];

        try {
            foreach ($files as $file) {
                $path = "{$basePath}/{$file['file_name']}";

                if (!$this->fileService->disk($organization)->put($path, $file['content'])) {
                    throw new DomainException(trans_message('workforce.errors.unexpected'));
                }

                $storedFiles[] = [
                    'file_type' => $file['file_type'],
                    'file_name' => $file['file_name'],
                    'storage_path' => $path,
                    'size_bytes' => strlen($file['content']),
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteFiles($organizationId, $storedFiles);
            throw $exception;
        }

        return $storedFiles;
    }

    private function deleteFiles(int $organizationId, array $files): void
    {
        if ($files === []) {
            return;
        }

        $organization = $this->organization($organizationId);
        $paths = array_column($files, 'storage_path');
        $this->fileService->disk($organization)->delete($paths);
    }

    private function exportRows(int $organizationId, int $periodId): Collection
    {
        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        return DB::table('workforce_payroll_source_rows')
            ->join('workforce_employees', 'workforce_employees.id', '=', 'workforce_payroll_source_rows.employee_id')
            ->leftJoin('projects', 'projects.id', '=', 'workforce_payroll_source_rows.project_id')
            ->leftJoin('production_labor_work_orders', 'production_labor_work_orders.id', '=', 'workforce_payroll_source_rows.work_order_id')
            ->where('workforce_payroll_source_rows.organization_id', $organizationId)
            ->where('workforce_payroll_source_rows.payroll_period_id', $periodId)
            ->orderBy('workforce_payroll_source_rows.work_date')
            ->get([
                'workforce_payroll_source_rows.*',
                'workforce_employees.personnel_number',
                'workforce_employees.external_payroll_ref',
                'workforce_employees.last_name',
                'workforce_employees.first_name',
                'workforce_employees.middle_name',
                'projects.name as project_name',
                'production_labor_work_orders.order_number as work_order_number',
            ])
            ->map(function (object $row) use ($organizationId, $period): array {
                $assignment = $this->assignmentForRow($organizationId, $row);
                $department = $assignment ? $this->assertRecord('workforce_departments', $organizationId, (int) $assignment->department_id) : null;
                $position = $assignment ? $this->assertRecord('workforce_positions', $organizationId, (int) $assignment->position_id) : null;
                $mapping = $this->resolveMapping($organizationId, $row, $assignment);

                return [
                    'period_start' => $period->period_start,
                    'period_end' => $period->period_end,
                    'personnel_number' => $row->personnel_number,
                    'external_payroll_ref' => $row->external_payroll_ref,
                    'employee_name' => trim(implode(' ', array_filter([$row->last_name, $row->first_name, $row->middle_name]))),
                    'department_code' => $department?->code,
                    'position_code' => $position?->code,
                    'project_id' => $row->project_id,
                    'project_name' => $row->project_name,
                    'work_order_number' => $row->work_order_number,
                    'work_date' => $row->work_date,
                    'source_type' => $row->source_type,
                    'hours' => $row->hours,
                    'amount' => $row->amount,
                    'cost_category_id' => $mapping?->cost_category_id,
                    'accounting_account' => $mapping?->accounting_account,
                    'source_document' => 'production-labor:' . $row->work_order_id,
                ];
            });
    }

    private function refreshAccountingIssues(int $organizationId, int $periodId): void
    {
        DB::table('workforce_payroll_validation_issues')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->where('issue_code', 'missing_accounting_mapping')
            ->delete();

        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);
        $employeeIds = DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->distinct()
            ->pluck('employee_id');
        $assignments = DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $period->period_end)
            ->where(function (Builder $query) use ($period): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $period->period_start);
            })
            ->orderBy('valid_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');
        $mappingLookup = [];

        foreach (DB::table('workforce_accounting_mappings')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get() as $mapping) {
            $key = $this->mappingLookupKey(
                (string) $mapping->scope_type,
                $mapping->scope_id !== null ? (int) $mapping->scope_id : null,
                (int) $mapping->priority
            );
            $mappingLookup[$key] ??= $mapping;
        }

        DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (
                $organizationId,
                $periodId,
                $assignments,
                $mappingLookup,
            ): void {
                $timestamp = now();
                $issues = [];

                foreach ($rows as $row) {
                    $assignment = $assignments
                        ->get($row->employee_id, collect())
                        ->first(fn (object $candidate): bool => $candidate->valid_from <= $row->work_date
                            && ($candidate->valid_to === null || $candidate->valid_to >= $row->work_date));
                    $mapping = $this->resolveMappingFromLookup($row, $assignment, $mappingLookup);

                    if (!$mapping || trim((string) ($mapping->accounting_account ?? '')) === '') {
                        $issues[] = [
                            'organization_id' => $organizationId,
                            'payroll_period_id' => $periodId,
                            'severity' => 'blocking',
                            'issue_code' => 'missing_accounting_mapping',
                            'message' => trans_message('workforce.validation.missing_accounting_mapping'),
                            'entity_type' => 'payroll_source_row',
                            'entity_id' => $row->id,
                            'employee_id' => $row->employee_id,
                            'project_id' => $row->project_id,
                            'payload' => json_encode(['work_date' => $row->work_date], JSON_THROW_ON_ERROR),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($issues !== []) {
                    DB::table('workforce_payroll_validation_issues')->insert($issues);
                }
            });
    }

    private function resolveMappingFromLookup(object $row, ?object $assignment, array $mappingLookup): ?object
    {
        $candidates = [];

        if ($assignment) {
            $candidates[] = ['staff_unit', (int) $assignment->staff_unit_id, 10];
            $candidates[] = ['department', (int) $assignment->department_id, 20];
        }

        $candidates[] = ['project', (int) $row->project_id, 30];
        $candidates[] = ['organization', null, 40];

        foreach ($candidates as [$scopeType, $scopeId, $priority]) {
            $mapping = $mappingLookup[$this->mappingLookupKey($scopeType, $scopeId, $priority)] ?? null;

            if ($mapping) {
                return $mapping;
            }
        }

        return null;
    }

    private function mappingLookupKey(string $scopeType, ?int $scopeId, int $priority): string
    {
        return "{$scopeType}:" . ($scopeId ?? 'null') . ":{$priority}";
    }

    private function resolveMapping(int $organizationId, object $row, ?object $assignment): ?object
    {
        $candidates = [];

        if ($assignment) {
            $candidates[] = ['staff_unit', (int) $assignment->staff_unit_id, 10];
            $candidates[] = ['department', (int) $assignment->department_id, 20];
        }

        $candidates[] = ['project', (int) $row->project_id, 30];
        $candidates[] = ['organization', null, 40];

        foreach ($candidates as [$scopeType, $scopeId, $priority]) {
            $mapping = DB::table('workforce_accounting_mappings')
                ->where('organization_id', $organizationId)
                ->where('scope_type', $scopeType)
                ->where('priority', $priority)
                ->where('is_active', true)
                ->when($scopeId === null, fn ($query) => $query->whereNull('scope_id'), fn ($query) => $query->where('scope_id', $scopeId))
                ->orderBy('id')
                ->first();

            if ($mapping) {
                return $mapping;
            }
        }

        return null;
    }

    private function assignmentForRow(int $organizationId, object $row): ?object
    {
        return DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $row->employee_id)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $row->work_date)
            ->where(function ($query) use ($row): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $row->work_date);
            })
            ->first();
    }

    private function lockPayrollSnapshotRows(int $organizationId, object $period): void
    {
        $sourceIds = static fn (string $column): Builder => DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $period->id)
            ->whereNotNull($column)
            ->select($column);
        $workOrderIds = static fn (): Builder => DB::table('production_labor_work_orders')
            ->where('organization_id', $organizationId)
            ->when(
                $period->project_id !== null,
                fn (Builder $query) => $query->where('project_id', $period->project_id)
            )
            ->select('id');
        $timesheetIds = static fn (): Builder => DB::table('production_labor_timesheets')
            ->where('organization_id', $organizationId)
            ->whereIn('work_order_id', $workOrderIds())
            ->whereBetween('shift_date', [$period->period_start, $period->period_end])
            ->select('id');
        $entryIds = static fn (): Builder => DB::table('production_labor_timesheet_entries')
            ->where('organization_id', $organizationId)
            ->whereIn('timesheet_id', $timesheetIds())
            ->select('id');
        $assignmentIds = static fn (string $column): Builder => DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->whereIn('employee_id', $sourceIds('employee_id'))
            ->whereDate('valid_from', '<=', $period->period_end)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $period->period_start))
            ->whereNotNull($column)
            ->select($column);

        $queries = [
            DB::table('workforce_payroll_periods')
                ->where('organization_id', $organizationId)
                ->where('id', $period->id),
            DB::table('workforce_payroll_source_rows')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $period->id),
            DB::table('production_labor_work_orders')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $workOrderIds()),
            DB::table('production_labor_timesheets')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $timesheetIds()),
            DB::table('production_labor_timesheet_entries')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $entryIds()),
            DB::table('production_labor_work_order_lines')
                ->where('organization_id', $organizationId)
                ->where(function (Builder $query) use ($sourceIds, $entryIds): void {
                    $query->whereIn('id', $sourceIds('work_order_line_id'))
                        ->orWhereIn(
                            'id',
                            DB::table('production_labor_timesheet_entries')
                                ->whereIn('id', $entryIds())
                                ->whereNotNull('work_order_line_id')
                                ->select('work_order_line_id')
                        );
                }),
            DB::table('production_labor_output_entries')
                ->where('organization_id', $organizationId)
                ->whereBetween('work_date', [$period->period_start, $period->period_end])
                ->when(
                    $period->project_id !== null,
                    fn (Builder $query) => $query->where('project_id', $period->project_id)
                ),
            DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->whereIn('employee_id', $sourceIds('employee_id'))
                ->whereDate('valid_from', '<=', $period->period_end)
                ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $period->period_start)),
            DB::table('workforce_work_schedules')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $assignmentIds('work_schedule_id')),
            DB::table('workforce_work_schedule_days')
                ->where('organization_id', $organizationId)
                ->whereIn('work_schedule_id', $assignmentIds('work_schedule_id'))
                ->whereBetween('work_date', [$period->period_start, $period->period_end]),
            DB::table('workforce_absences')
                ->where('organization_id', $organizationId)
                ->whereIn('employee_id', $sourceIds('employee_id'))
                ->whereDate('start_date', '<=', $period->period_end)
                ->whereDate('end_date', '>=', $period->period_start),
            DB::table('workforce_absence_types')
                ->where('organization_id', $organizationId),
            DB::table('workforce_accounting_mappings')
                ->where('organization_id', $organizationId),
            DB::table('workforce_employees')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $sourceIds('employee_id')),
            DB::table('workforce_departments')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $assignmentIds('department_id')),
            DB::table('workforce_positions')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $assignmentIds('position_id')),
            DB::table('workforce_staff_units')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $assignmentIds('staff_unit_id')),
            DB::table('projects')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $sourceIds('project_id')),
        ];

        foreach ($queries as $query) {
            $query->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    private function assertProductionSourceActual(int $organizationId, object $period): void
    {
        if (! $this->isProductionSourceActual($organizationId, $period)) {
            throw new DomainException(trans_message('workforce.errors.payroll_source_changed'));
        }
    }

    private function isProductionSourceActual(int $organizationId, object $period): bool
    {
        $sourceQuery = DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $period->id)
            ->orderBy('timesheet_entry_id')
            ->select([
                'timesheet_entry_id',
                'employee_id',
                'project_id',
                'work_order_id',
                'work_order_line_id',
                'work_date',
                'hours',
                'amount',
            ]);
        $productionQuery = $this->productionSourceQuery($organizationId, $period);

        return $this->hashRows($sourceQuery, fn (object $row): array => $this->canonicalSourceRow($row))
            === $this->hashRows($productionQuery, fn (object $row): array => $this->canonicalSourceRow($row));
    }

    private function payrollSnapshotHash(int $organizationId, object $period): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([
            'organization_id' => $organizationId,
            'period_id' => (int) $period->id,
            'project_id' => $period->project_id !== null ? (int) $period->project_id : null,
            'period_start' => (string) $period->period_start,
            'period_end' => (string) $period->period_end,
        ], JSON_THROW_ON_ERROR));
        $sourceIds = static fn (string $column): Builder => DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $period->id)
            ->whereNotNull($column)
            ->select($column);
        $assignmentIds = static fn (string $column): Builder => DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->whereIn('employee_id', $sourceIds('employee_id'))
            ->whereDate('valid_from', '<=', $period->period_end)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $period->period_start))
            ->whereNotNull($column)
            ->select($column);
        $queries = [
            'source' => DB::table('workforce_payroll_source_rows')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $period->id)
                ->orderBy('id'),
            'production' => $this->productionSourceQuery($organizationId, $period),
            'outputs' => DB::table('production_labor_output_entries as output')
                ->join('production_labor_work_orders as output_work_order', 'output_work_order.id', '=', 'output.work_order_id')
                ->where('output.organization_id', $organizationId)
                ->where('output.status', 'accepted')
                ->whereNull('output.deleted_at')
                ->whereNull('output_work_order.deleted_at')
                ->whereIn('output_work_order.status', ['accepted', 'closed'])
                ->whereBetween('output.work_date', [$period->period_start, $period->period_end])
                ->when($period->project_id !== null, fn (Builder $query) => $query->where('output.project_id', $period->project_id))
                ->select('output.*')
                ->orderBy('output.id'),
            'assignments' => DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->whereIn('employee_id', $sourceIds('employee_id'))
                ->whereDate('valid_from', '<=', $period->period_end)
                ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $period->period_start))
                ->orderBy('id'),
            'schedules' => DB::table('workforce_work_schedules')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $assignmentIds('work_schedule_id'))
                ->orderBy('id'),
            'schedule_days' => DB::table('workforce_work_schedule_days')
                ->where('organization_id', $organizationId)
                ->whereIn('work_schedule_id', $assignmentIds('work_schedule_id'))
                ->whereBetween('work_date', [$period->period_start, $period->period_end])
                ->orderBy('id'),
            'absences' => DB::table('workforce_absences')
                ->where('organization_id', $organizationId)
                ->whereIn('employee_id', $sourceIds('employee_id'))
                ->whereDate('start_date', '<=', $period->period_end)
                ->whereDate('end_date', '>=', $period->period_start)
                ->orderBy('id'),
            'absence_types' => DB::table('workforce_absence_types')->where('organization_id', $organizationId)->orderBy('id'),
            'mappings' => DB::table('workforce_accounting_mappings')->where('organization_id', $organizationId)->orderBy('id'),
            'employees' => DB::table('workforce_employees')->where('organization_id', $organizationId)->whereIn('id', $sourceIds('employee_id'))->orderBy('id'),
            'departments' => DB::table('workforce_departments')->where('organization_id', $organizationId)->whereIn('id', $assignmentIds('department_id'))->orderBy('id'),
            'positions' => DB::table('workforce_positions')->where('organization_id', $organizationId)->whereIn('id', $assignmentIds('position_id'))->orderBy('id'),
            'staff_units' => DB::table('workforce_staff_units')->where('organization_id', $organizationId)->whereIn('id', $assignmentIds('staff_unit_id'))->orderBy('id'),
            'projects' => DB::table('projects')->where('organization_id', $organizationId)->whereIn('id', $sourceIds('project_id'))->orderBy('id'),
            'work_orders' => DB::table('production_labor_work_orders')->where('organization_id', $organizationId)->whereIn('id', $sourceIds('work_order_id'))->orderBy('id'),
            'work_order_lines' => DB::table('production_labor_work_order_lines')->where('organization_id', $organizationId)->whereIn('id', $sourceIds('work_order_line_id'))->orderBy('id'),
        ];

        foreach ($queries as $section => $query) {
            hash_update($hash, $section);
            foreach ($query->cursor() as $row) {
                $data = array_diff_key((array) $row, array_flip(['created_at', 'updated_at']));
                hash_update($hash, json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            }
        }

        return hash_final($hash);
    }

    private function productionSourceQuery(int $organizationId, object $period): Builder
    {
        return DB::table('production_labor_timesheet_entries as entry')
            ->join('production_labor_timesheets as timesheet', 'timesheet.id', '=', 'entry.timesheet_id')
            ->join('production_labor_work_orders as work_order', 'work_order.id', '=', 'timesheet.work_order_id')
            ->leftJoin('production_labor_work_order_lines as line', 'line.id', '=', 'entry.work_order_line_id')
            ->where('entry.organization_id', $organizationId)
            ->where('entry.include_in_payroll', true)
            ->whereNotNull('entry.employee_id')
            ->whereBetween('timesheet.shift_date', [$period->period_start, $period->period_end])
            ->whereIn('work_order.status', ['accepted', 'closed'])
            ->whereNull('entry.deleted_at')
            ->whereNull('timesheet.deleted_at')
            ->whereNull('work_order.deleted_at')
            ->whereNull('line.deleted_at')
            ->when($period->project_id !== null, fn (Builder $query) => $query->where('work_order.project_id', $period->project_id))
            ->orderBy('entry.id')
            ->selectRaw(
                'entry.id as timesheet_entry_id, entry.employee_id, timesheet.project_id, '
                . 'timesheet.work_order_id, entry.work_order_line_id, timesheet.shift_date as work_date, '
                . 'entry.hours, (entry.hours * COALESCE(line.hour_rate, 0)) as amount'
            );
    }

    private function hashRows(Builder $query, callable $normalize): string
    {
        $hash = hash_init('sha256');

        foreach ($query->cursor() as $row) {
            hash_update($hash, json_encode($normalize($row), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        }

        return hash_final($hash);
    }

    private function canonicalSourceRow(object $row): array
    {
        return [
            'timesheet_entry_id' => (int) $row->timesheet_entry_id,
            'employee_id' => (int) $row->employee_id,
            'project_id' => (int) $row->project_id,
            'work_order_id' => (int) $row->work_order_id,
            'work_order_line_id' => (int) $row->work_order_line_id,
            'work_date' => (string) $row->work_date,
            'hours' => number_format((float) $row->hours, 4, '.', ''),
            'amount' => number_format((float) $row->amount, 2, '.', ''),
        ];
    }

    private function csv(Collection $rows, array $headers): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $header) => $row[$header] ?? null, $headers));
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }

    private function summaryCsv(Collection $rows): string
    {
        $summary = $rows
            ->groupBy('personnel_number')
            ->map(fn (Collection $items): array => [
                'personnel_number' => $items->first()['personnel_number'],
                'employee_name' => $items->first()['employee_name'],
                'hours' => round((float) $items->sum('hours'), 2),
                'amount' => round((float) $items->sum('amount'), 2),
            ])
            ->values();

        return $this->csv($summary, ['personnel_number', 'employee_name', 'hours', 'amount']);
    }

    private function assertSourceHashActual(int $organizationId, object $period): void
    {
        $this->assertProductionSourceActual($organizationId, $period);
        $currentHash = $this->payrollSnapshotHash($organizationId, $period);

        if (($period->source_hash ?? null) !== $currentHash) {
            throw new DomainException(trans_message('workforce.errors.payroll_source_changed'));
        }
    }

    private function assertExportTransition(string $currentStatus, string $nextStatus): void
    {
        $allowed = [
            'created' => ['sent', 'rejected'],
            'sent' => ['accepted', 'rejected'],
            'accepted' => [],
            'rejected' => [],
        ];

        if (!in_array($nextStatus, $allowed[$currentStatus] ?? [], true)) {
            throw new DomainException(trans_message('workforce.errors.export_status_transition_forbidden'));
        }
    }

    private function hasBlockingIssues(int $organizationId, int $periodId): bool
    {
        return DB::table('workforce_payroll_validation_issues')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->where('severity', 'blocking')
            ->exists();
    }

    private function assertSourceRows(int $organizationId, int $periodId): void
    {
        if (! $this->hasSourceRows($organizationId, $periodId)) {
            throw new DomainException(trans_message('workforce.errors.payroll_source_empty'));
        }
    }

    private function hasSourceRows(int $organizationId, int $periodId): bool
    {
        return DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->exists();
    }

    private function assertMappingScope(int $organizationId, array $payload): void
    {
        if (!empty($payload['cost_category_id']) && !DB::table('cost_categories')->where('organization_id', $organizationId)->where('id', $payload['cost_category_id'])->exists()) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        $scopeType = $payload['scope_type'];
        $scopeId = $payload['scope_id'] ?? null;

        if ($scopeType === 'organization') {
            return;
        }

        if ($scopeId === null) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        match ($scopeType) {
            'staff_unit' => $this->assertRecord('workforce_staff_units', $organizationId, (int) $scopeId),
            'department' => $this->assertRecord('workforce_departments', $organizationId, (int) $scopeId),
            'project' => $this->assertProject($organizationId, (int) $scopeId),
            default => throw new DomainException(trans_message('workforce.errors.record_not_found')),
        };
    }

    private function normalizeMappingPayload(array $payload): array
    {
        if (($payload['scope_type'] ?? null) === 'organization') {
            $payload['scope_id'] = null;
        }

        if (!array_key_exists('priority', $payload) || $payload['priority'] === null) {
            $payload['priority'] = match ($payload['scope_type'] ?? null) {
                'staff_unit' => 10,
                'department' => 20,
                'project' => 30,
                default => 40,
            };
        }

        return $payload;
    }

    private function decorateAccountingMapping(int $organizationId, ?object $record): array
    {
        if (!$record) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        $data = (array) $record;
        $data['scope_label'] = $this->scopeLabel($organizationId, (string) $record->scope_type, $record->scope_id !== null ? (int) $record->scope_id : null);

        return $data;
    }

    private function decorateExportPackage(int $organizationId, object $record): array
    {
        $data = (array) $record;
        $data['status_label'] = trans_message("workforce.statuses.{$record->status}");
        $data['payroll_period_label'] = isset($record->payroll_period_start, $record->payroll_period_end)
            ? sprintf('%s - %s', $record->payroll_period_start, $record->payroll_period_end)
            : $this->payrollPeriodLabel($organizationId, (int) $record->payroll_period_id);
        $data['workflow_summary'] = [
            'label' => $data['payroll_period_label'],
            'description' => trans_message("workforce.workflow.export_package_{$record->status}"),
        ];

        return $data;
    }

    private function payrollPeriodLabel(int $organizationId, int $periodId): ?string
    {
        $period = DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)
            ->where('id', $periodId)
            ->first(['period_start', 'period_end']);

        return $period ? sprintf('%s - %s', $period->period_start, $period->period_end) : null;
    }

    private function scopeLabel(int $organizationId, string $scopeType, ?int $scopeId): string
    {
        if ($scopeType === 'organization') {
            return trans_message('workforce.scope_labels.organization');
        }

        $table = match ($scopeType) {
            'project' => 'projects',
            'department' => 'workforce_departments',
            'staff_unit' => 'workforce_staff_units',
            default => null,
        };

        if ($table === null || $scopeId === null) {
            return trans_message('workforce.scope_labels.unknown');
        }

        $field = $table === 'workforce_staff_units' ? 'code' : 'name';
        $label = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('id', $scopeId)
            ->value($field);

        return $label ? (string) $label : trans_message("workforce.scope_labels.{$scopeType}");
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        if (!Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.project_not_found'));
        }
    }

    private function assertRecord(string $table, int $organizationId, int $id): object
    {
        $record = DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->first();

        if (!$record) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        return $record;
    }

    private function organization(int $organizationId): Organization
    {
        return Organization::query()->findOrFail($organizationId);
    }

    private function lockOrganization(int $organizationId): void
    {
        Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
