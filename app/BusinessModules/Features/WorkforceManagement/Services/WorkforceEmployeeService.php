<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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

        return WorkforceEmployee::create(array_merge($payload, [
            'organization_id' => $organizationId,
            'employment_status' => $payload['employment_status'] ?? 'active',
        ]))->load('user:id,name,email,current_organization_id');
    }

    public function update(int $organizationId, int $employeeId, array $payload): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
        $employee->update($payload);

        return $employee->refresh()->load('user:id,name,email,current_organization_id');
    }

    public function dismiss(int $organizationId, int $employeeId, ?string $dismissalDate = null): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $employee->update([
            'employment_status' => 'dismissed',
            'dismissal_date' => $dismissalDate ?? now()->toDateString(),
        ]);

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
}
