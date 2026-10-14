<?php

declare(strict_types=1);

namespace App\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkingTimezoneResolver;
use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Organization;
use App\Models\TimeEntry;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Application boundary shared by measured-work writers and period approval. */
final class WorkforcePeriodMutationGuard
{
    public function lockOrganization(int $organizationId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Organization mutex requires a transaction.');
        }
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
    }

    public function entryFacts(TimeEntry $entry): array
    {
        return array_replace($entry->getAttributes(), [
            'work_date' => $entry->work_date->format('Y-m-d'),
            'start_at' => $entry->start_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'end_at' => $entry->end_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'custom_fields' => $entry->custom_fields ?? [],
        ]);
    }

    /** Call under the organization mutex for writes; preview may call read-only. */
    public function assertMutable(int $organizationId, ?int $projectId, array $fact): void
    {
        [$from, $to] = $this->dateBounds($organizationId, $fact);
        if (Schema::hasTable('workforce_timekeeping_periods') && ! $this->isConfirmedCivilOnly($organizationId, $fact, $from, $to)) {
            $locked = DB::table('workforce_timekeeping_periods as period')
                ->where('period.organization_id', $organizationId)->whereIn('period.status', ['approved', 'closed'])
                ->where('period.month', '>=', substr($from, 0, 7).'-01')->where('period.month', '<=', substr($to, 0, 7).'-01')
                ->when($projectId !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('period.project_id')->orWhere('period.project_id', $projectId)))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workforce_timekeeping_periods as successor')->whereColumn('successor.supersedes_id', 'period.id')->whereColumn('successor.organization_id', 'period.organization_id'))
                ->exists();
            if ($locked) {
                throw new DomainException(trans_message('workforce.errors.timekeeping_closed'), 409);
            }
        }
        if (Schema::hasTable('workforce_payroll_periods') && DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)->where('status', 'locked')
            ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)
            ->when($projectId !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('project_id')->orWhere('project_id', $projectId)))->exists()) {
            throw new DomainException(trans_message('workforce.errors.timekeeping_closed'), 409);
        }
    }

    private function isConfirmedCivilOnly(int $organizationId, array $fact, string $from, string $to): bool
    {
        if (! Schema::hasTable('workforce_employment_contracts') || ! Schema::hasColumn('workforce_employment_contracts', 'relation_type')) {
            return false;
        }
        $userId = (int) ($fact['user_id'] ?? 0);
        $employeeId = (int) ($fact['employee_id'] ?? 0);
        if ($userId <= 0 && $employeeId <= 0) {
            return false;
        }
        $employees = WorkforceEmployee::query()->where('organization_id', $organizationId)
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId), fn ($query) => $query->whereKey($employeeId))->select('id');
        $contracts = EmploymentContract::query()->where('organization_id', $organizationId)->whereIn('employee_id', $employees)
            ->whereIn('status', ['active', 'closed'])->whereDate('start_date', '<=', $to)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from));
        // Unknown legacy bindings and concurrent employment retain the conservative guard.
        if ((clone $contracts)->where(fn ($query) => $query->whereNull('relation_type')->orWhere('relation_type', '!=', 'civil'))->exists()) {
            return false;
        }

        return (clone $contracts)->where('relation_type', 'civil')->whereDate('start_date', '<=', $from)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $to))->exists();
    }

    private function dateBounds(int $organizationId, array $fact): array
    {
        $date = substr((string) ($fact['work_date'] ?? $fact['shift_date'] ?? ''), 0, 10);
        $start = empty($fact['start_at']) ? null : new DateTimeImmutable((string) $fact['start_at']);
        $end = empty($fact['end_at']) ? $start : (new DateTimeImmutable((string) $fact['end_at']))->modify('-1 microsecond');
        $userId = (int) ($fact['user_id'] ?? 0);
        $contractId = $fact['employment_contract_id'] ?? $fact['custom_fields']['employment_contract_id'] ?? null;
        $zones = [];
        $candidates = [$date];
        if ($start) {
            foreach ([$start, $end] as $boundary) {
                foreach ([-1, 0, 1] as $offset) {
                    $candidates[] = $boundary->setTimezone(new DateTimeZone('UTC'))->modify($offset.' days')->format('Y-m-d');
                }
            }
        }
        foreach (array_unique(array_filter($candidates)) as $candidate) {
            try {
                $resolved = app(WorkingTimezoneResolver::class)->resolve($organizationId, $userId, $candidate, $contractId === null ? null : (int) $contractId);
                if ($resolved['timezone'] !== null) {
                    $zones[$resolved['timezone']] = true;
                }
            } catch (ValidationException $exception) {
                // An explicitly linked contract may begin/end between neighbouring dates.
            }
        }
        if (count($zones) === 1) {
            $zone = new DateTimeZone((string) array_key_first($zones));
            if (! empty($fact['timezone_requires_reconciliation']) && ($fact['custom_fields']['time_tracking_interval']['clock_source'] ?? 'legacy_local') === 'legacy_local' && ! empty($fact['start_time'])) {
                try {
                    $localInput = ['work_date' => $date, 'start_time' => $fact['start_time'], 'timezone' => $zone->getName()];
                    if (! empty($fact['end_time'])) {
                        $interval = WorkTimeInterval::fromInput($localInput + ['end_time' => $fact['end_time']]);
                        $start = $interval->start;
                        $end = $interval->end->modify('-1 microsecond');
                    } else {
                        $start = new DateTimeImmutable(WorkTimeInterval::normalizeStart($localInput)['start_at']);
                        $end = $start;
                    }
                } catch (\InvalidArgumentException $exception) {
                    $local = new DateTimeImmutable($date.'T00:00:00Z');

                    return [$local->modify('-1 day')->format('Y-m-d'), $local->modify('+2 days')->format('Y-m-d')];
                }
            }
            if ($start && $end) {
                $localDates = [$start->setTimezone($zone)->format('Y-m-d'), $end->setTimezone($zone)->format('Y-m-d')];
                $confirmed = true;
                foreach (array_unique($localDates) as $localDate) {
                    try {
                        $resolved = app(WorkingTimezoneResolver::class)->resolve($organizationId, $userId, $localDate, $contractId === null ? null : (int) $contractId);
                        $confirmed = $confirmed && $resolved['timezone'] === $zone->getName();
                    } catch (ValidationException $exception) {
                        $confirmed = false;
                    }
                }
                if ($confirmed) {
                    return $localDates;
                }
            } else {
                return [$date, $date];
            }
        }
        // Unknown employment timezone: protect every possible local date, never guess UTC.
        $base = new DateTimeImmutable($date.'T00:00:00Z');
        $from = $start ? $start->setTimezone(new DateTimeZone('UTC'))->modify('-14 hours')->format('Y-m-d') : $base->modify('-1 day')->format('Y-m-d');
        $to = $end ? $end->setTimezone(new DateTimeZone('UTC'))->modify('+14 hours')->format('Y-m-d') : $base->modify('+2 days')->format('Y-m-d');

        return [$from, $to];
    }
}
