<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class WorkforceSummaryService
{
    public function __construct(private readonly HrAccess $access) {}

    public function summary(int $organizationId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.view');
        $result = [];
        foreach (['employees', 'departments', 'positions', 'staff_units', 'absences', 'business_trips'] as $entity) {
            $query = DB::table('workforce_'.$entity)->where('organization_id', $organizationId)->whereNull('deleted_at');
            $result[$entity] = ['total' => (clone $query)->count()];
            if ($entity === 'employees') {
                $result[$entity]['active'] = (clone $query)->where('employment_status', 'active')->count();
            } elseif (in_array($entity, ['absences', 'business_trips'], true)) {
                $result[$entity]['draft'] = (clone $query)->where('status', 'draft')->count();
            }
        }
        if ($this->access->allows($organizationId, $actor, 'workforce.payroll-source.view')) {
            $query = DB::table('workforce_payroll_periods')->where('organization_id', $organizationId);
            $result['payroll_periods'] = ['total' => (clone $query)->count(), 'locked' => (clone $query)->where('status', 'locked')->count()];
        }
        if ($this->access->allows($organizationId, $actor, 'workforce.exports.view')) {
            $query = DB::table('workforce_export_packages')->where('organization_id', $organizationId);
            $result['export_packages'] = ['total' => (clone $query)->count(), 'pending' => (clone $query)->whereIn('status', ['created', 'sent'])->count()];
        }

        return $result;
    }
}
