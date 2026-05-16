<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkforceCorporateService
{
    public function __construct(private readonly FileService $fileService)
    {
    }

    public function listAccountingMappings(int $organizationId): Collection
    {
        return DB::table('workforce_accounting_mappings')
            ->where('organization_id', $organizationId)
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get();
    }

    public function storeAccountingMapping(int $organizationId, array $payload): array
    {
        $payload = $this->normalizeMappingPayload($payload);
        $this->assertMappingScope($organizationId, $payload);

        $id = DB::table('workforce_accounting_mappings')->insertGetId(array_merge($payload, [
            'organization_id' => $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return (array) DB::table('workforce_accounting_mappings')->where('organization_id', $organizationId)->where('id', $id)->first();
    }

    public function updateAccountingMapping(int $organizationId, int $mappingId, array $payload): array
    {
        $current = $this->assertRecord('workforce_accounting_mappings', $organizationId, $mappingId);
        $merged = $this->normalizeMappingPayload(array_merge((array) $current, $payload));
        $this->assertMappingScope($organizationId, $merged);

        DB::table('workforce_accounting_mappings')
            ->where('organization_id', $organizationId)
            ->where('id', $mappingId)
            ->update(array_merge($this->normalizeMappingPayload($payload + ['scope_type' => $merged['scope_type']]), ['updated_at' => now()]));

        return (array) DB::table('workforce_accounting_mappings')->where('organization_id', $organizationId)->where('id', $mappingId)->first();
    }

    public function lockPayrollPeriod(int $organizationId, int $periodId, int $userId): array
    {
        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        if ($period->status === 'locked') {
            return (array) $period;
        }

        if ($period->status !== 'validated') {
            throw new DomainException(trans_message('workforce.errors.payroll_period_not_validated'));
        }

        $this->assertSourceRows($organizationId, $periodId);
        $this->refreshAccountingIssues($organizationId, $periodId);
        $this->assertNoBlockingIssues($organizationId, $periodId);

        $sourceHash = $this->sourceHash($organizationId, $periodId);

        DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)
            ->where('id', $periodId)
            ->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by_user_id' => $userId,
                'source_hash' => $sourceHash,
                'updated_at' => now(),
            ]);

        return (array) DB::table('workforce_payroll_periods')->where('organization_id', $organizationId)->where('id', $periodId)->first();
    }

    public function exportPackages(int $organizationId): Collection
    {
        return DB::table('workforce_export_packages')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    public function showExportPackage(int $organizationId, int $packageId): array
    {
        $package = (array) $this->assertRecord('workforce_export_packages', $organizationId, $packageId);
        $package['files'] = DB::table('workforce_export_package_files')
            ->where('organization_id', $organizationId)
            ->where('export_package_id', $packageId)
            ->orderBy('id')
            ->get()
            ->all();

        return $package;
    }

    public function createExportPackage(int $organizationId, int $periodId, int $userId): array
    {
        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        if ($period->status !== 'locked') {
            throw new DomainException(trans_message('workforce.errors.payroll_period_not_locked'));
        }

        $this->assertSourceRows($organizationId, $periodId);
        $this->assertSourceHashActual($organizationId, $period);

        if ($this->hasPackageWithStatus($organizationId, $periodId, ['accepted'])) {
            throw new DomainException(trans_message('workforce.errors.export_package_accepted'));
        }

        if ($this->hasPackageWithStatus($organizationId, $periodId, ['created', 'sent'])) {
            throw new DomainException(trans_message('workforce.errors.export_package_exists'));
        }

        $rows = $this->exportRows($organizationId, $periodId);
        $sourceHash = $this->sourceHash($organizationId, $periodId);
        $rejectedPackage = DB::table('workforce_export_packages')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->where('status', 'rejected')
            ->orderByDesc('id')
            ->first();

        return DB::transaction(function () use ($organizationId, $periodId, $userId, $rows, $sourceHash, $rejectedPackage): array {
            $packageId = DB::table('workforce_export_packages')->insertGetId([
                'organization_id' => $organizationId,
                'payroll_period_id' => $periodId,
                'supersedes_package_id' => $rejectedPackage?->id,
                'package_number' => 'WF-' . $periodId . '-' . now()->format('YmdHis') . '-' . uniqid(),
                'status' => 'created',
                'source_hash' => $sourceHash,
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($this->buildFiles($organizationId, $periodId, $packageId, $rows) as $file) {
                DB::table('workforce_export_package_files')->insert(array_merge($file, [
                    'organization_id' => $organizationId,
                    'export_package_id' => $packageId,
                    'storage_disk' => 's3',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            return $this->showExportPackage($organizationId, $packageId);
        });
    }

    public function markExportPackage(int $organizationId, int $packageId, string $status, ?string $reason = null): array
    {
        $package = $this->assertRecord('workforce_export_packages', $organizationId, $packageId);
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

    private function buildFiles(int $organizationId, int $periodId, int $packageId, Collection $rows): array
    {
        $organization = $this->organization($organizationId);
        $basePath = "org-{$organizationId}/workforce/payroll-exports/period-{$periodId}/package-{$packageId}";
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

        return array_map(function (array $file) use ($organization, $basePath): array {
            $path = "{$basePath}/{$file['file_name']}";
            $this->fileService->disk($organization)->put($path, $file['content']);

            return [
                'file_type' => $file['file_type'],
                'file_name' => $file['file_name'],
                'storage_path' => $path,
                'size_bytes' => strlen($file['content']),
            ];
        }, $files);
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

        $rows = DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->get();

        foreach ($rows as $row) {
            if (!$this->resolveMapping($organizationId, $row, $this->assignmentForRow($organizationId, $row))) {
                DB::table('workforce_payroll_validation_issues')->insert([
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
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
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

    private function sourceHash(int $organizationId, int $periodId): string
    {
        $rows = DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'project_id', 'work_order_id', 'timesheet_entry_id', 'work_date', 'hours', 'amount']);

        return hash('sha256', json_encode($rows->all(), JSON_THROW_ON_ERROR));
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

    private function hasPackageWithStatus(int $organizationId, int $periodId, array $statuses): bool
    {
        return DB::table('workforce_export_packages')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('status', $statuses)
            ->exists();
    }

    private function assertSourceHashActual(int $organizationId, object $period): void
    {
        $currentHash = $this->sourceHash($organizationId, (int) $period->id);

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

    private function assertNoBlockingIssues(int $organizationId, int $periodId): void
    {
        if (DB::table('workforce_payroll_validation_issues')->where('organization_id', $organizationId)->where('payroll_period_id', $periodId)->where('severity', 'blocking')->exists()) {
            throw new DomainException(trans_message('workforce.errors.payroll_period_has_blocking_issues'));
        }
    }

    private function assertSourceRows(int $organizationId, int $periodId): void
    {
        if (!DB::table('workforce_payroll_source_rows')->where('organization_id', $organizationId)->where('payroll_period_id', $periodId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.payroll_source_empty'));
        }
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
}
