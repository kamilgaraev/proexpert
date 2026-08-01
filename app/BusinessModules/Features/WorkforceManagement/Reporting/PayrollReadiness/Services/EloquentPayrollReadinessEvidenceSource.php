<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessEvidenceSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use Illuminate\Support\Facades\DB;

final class EloquentPayrollReadinessEvidenceSource implements PayrollReadinessEvidenceSource
{
    public function sourceRows(PayrollReadinessPeriodIdentity $period): iterable
    {
        return DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $period->organizationId)
            ->where('payroll_period_id', $period->periodId)
            ->orderBy('id')
            ->select([
                'id',
                'organization_id',
                'payroll_period_id',
                'employee_id',
                'project_id',
                'work_order_id',
                'work_order_line_id',
                'timesheet_entry_id',
                'work_date',
                'source_type',
                'hours',
                'amount',
                'payload',
            ])
            ->cursor()
            ->map(static fn (object $row): array => (array) $row);
    }

    public function validationIssues(PayrollReadinessPeriodIdentity $period): iterable
    {
        return DB::table('workforce_payroll_validation_issues')
            ->where('organization_id', $period->organizationId)
            ->where('payroll_period_id', $period->periodId)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->select([
                'id',
                'organization_id',
                'payroll_period_id',
                'severity',
                'issue_code',
                'entity_type',
                'entity_id',
                'employee_id',
                'project_id',
                'payload',
                'resolved_at',
            ])
            ->cursor()
            ->map(static fn (object $row): array => (array) $row);
    }
}
