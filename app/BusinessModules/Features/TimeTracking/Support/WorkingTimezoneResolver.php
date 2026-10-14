<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Support;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Tenant-scoped read adapter; callers authorize the actor before resolving employment terms. */
final class WorkingTimezoneResolver
{
    public function resolve(int $organizationId, int $userId, string $date, ?int $contractId = null): array
    {
        if ($organizationId <= 0 || $userId <= 0 || ! Schema::hasTable('workforce_employment_contracts') || ! Schema::hasColumn('workforce_employment_contracts', 'timezone')) {
            if ($contractId !== null) {
                throw $this->invalidContract();
            }

            return ['timezone' => null, 'employment_contract_id' => null];
        }
        $employeeIds = WorkforceEmployee::query()->where('organization_id', $organizationId)->where('user_id', $userId)->select('id');
        $query = EmploymentContract::query()->where('organization_id', $organizationId)->whereIn('employee_id', $employeeIds)
            ->where(static fn ($query) => $query->where('relation_type', 'employment')->when($contractId === null, fn ($query) => $query->orWhereNull('relation_type')))->whereIn('status', ['active', 'closed'])
            ->whereDate('start_date', '<=', $date)->where(static fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
        if ($contractId !== null) {
            $query->whereKey($contractId);
        }
        $contracts = $query->get(['id', 'timezone'])->map(static fn (EmploymentContract $contract): array => ['id' => (int) $contract->id, 'timezone' => $contract->timezone])->all();
        if ($contractId !== null && $contracts === []) {
            throw $this->invalidContract();
        }

        return self::confirmedTimezone($contracts, $contractId);
    }

    /** Null or conflicting contract zones never establish a working timezone. */
    public static function confirmedTimezone(array $contracts, ?int $explicitContractId = null): array
    {
        if ($explicitContractId !== null) {
            $contracts = array_values(array_filter($contracts, static fn (array $contract): bool => (int) $contract['id'] === $explicitContractId));
        }
        $zones = [];
        foreach ($contracts as $contract) {
            $timezone = $contract['timezone'] ?? null;
            if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                return ['timezone' => null, 'employment_contract_id' => null];
            }
            try {
                new \DateTimeZone($timezone);
            } catch (\Throwable $exception) {
                return ['timezone' => null, 'employment_contract_id' => null];
            }
            $zones[$timezone] = true;
        }

        return count($zones) === 1
            ? ['timezone' => (string) array_key_first($zones), 'employment_contract_id' => count($contracts) === 1 ? (int) $contracts[0]['id'] : null]
            : ['timezone' => null, 'employment_contract_id' => null];
    }

    private function invalidContract(): ValidationException
    {
        return ValidationException::withMessages(['employment_contract_id' => trans_message('workforce.errors.hr_reconciliation_required')]);
    }
}
