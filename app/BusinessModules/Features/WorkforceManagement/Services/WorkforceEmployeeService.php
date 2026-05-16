<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WorkforceEmployeeService
{
    public function paginate(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('employment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('personnel_number', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('external_payroll_ref', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    public function create(int $organizationId, array $payload): WorkforceEmployee
    {
        $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
        $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null);

        return WorkforceEmployee::create(array_merge($payload, [
            'organization_id' => $organizationId,
            'employment_status' => $payload['employment_status'] ?? 'active',
        ]))->load('user:id,name,email,current_organization_id');
    }

    public function update(int $organizationId, int $employeeId, array $payload): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
        $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null, $employeeId);
        $employee->update($payload);

        return $employee->refresh()->load('user:id,name,email,current_organization_id');
    }

    public function dismiss(int $organizationId, int $employeeId, ?string $dismissalDate = null): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $date = $dismissalDate ?? now()->toDateString();

        if ($employee->hire_date !== null && Carbon::parse($date)->lt(Carbon::parse($employee->hire_date))) {
            throw new DomainException(trans_message('workforce.errors.dismissal_before_hire_date'));
        }

        $this->assertDismissalDoesNotChangeLockedPayrollSource($organizationId, (int) $employee->id, $date);

        DB::transaction(function () use ($employee, $organizationId, $date): void {
            $employee->update([
                'employment_status' => 'dismissed',
                'dismissal_date' => $date,
            ]);

            DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>', $date);
                })
                ->update([
                    'valid_to' => $date,
                    'updated_at' => now(),
                ]);
        });

        return $employee->refresh()->load('user:id,name,email,current_organization_id');
    }

    public function find(int $organizationId, int $employeeId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->find($employeeId);

        if (!$employee) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }

        return $employee;
    }

    private function assertUserBelongsToOrganization(int $organizationId, mixed $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = User::query()
            ->whereKey((int) $userId)
            ->where('current_organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('workforce.errors.user_not_found'));
        }
    }

    private function assertActiveUserAssignmentIsUnique(int $organizationId, mixed $userId, ?int $ignoreEmployeeId = null): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', (int) $userId)
            ->where('employment_status', 'active')
            ->when($ignoreEmployeeId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreEmployeeId))
            ->exists();

        if ($exists) {
            throw new DomainException(trans_message('workforce.errors.employee_user_already_active'));
        }
    }

    private function assertDismissalDoesNotChangeLockedPayrollSource(int $organizationId, int $employeeId, string $dismissalDate): void
    {
        $exists = DB::table('workforce_payroll_periods')
            ->join('workforce_payroll_source_rows', 'workforce_payroll_source_rows.payroll_period_id', '=', 'workforce_payroll_periods.id')
            ->where('workforce_payroll_periods.organization_id', $organizationId)
            ->where('workforce_payroll_periods.status', 'locked')
            ->where('workforce_payroll_source_rows.employee_id', $employeeId)
            ->whereDate('workforce_payroll_periods.period_start', '<=', $dismissalDate)
            ->whereDate('workforce_payroll_periods.period_end', '>=', $dismissalDate)
            ->exists();

        if ($exists) {
            throw new DomainException(trans_message('workforce.errors.dismissal_locked_payroll_period'));
        }
    }
}
