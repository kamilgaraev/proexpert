<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Contracts\WorkforcePersonNameProvider;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class EloquentWorkforcePersonNameProvider implements WorkforcePersonNameProvider
{
    public function employeeNameAt(int $organizationId, int $userId, DateTimeInterface $date): ?string
    {
        return $this->employeeNamesAt($organizationId, [
            0 => ['user_id' => $userId, 'date' => $date],
        ])[0] ?? null;
    }

    public function employeeNamesAt(int $organizationId, array $references): array
    {
        if ($references === []) {
            return [];
        }

        $dates = collect($references)->map(
            static fn (array $reference): string => $reference['date']->format('Y-m-d'),
        );
        $employees = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->whereIn('user_id', collect($references)->pluck('user_id')->unique()->all())
            ->whereDate('hire_date', '<=', (string) $dates->max())
            ->where(function (Builder $query) use ($dates): void {
                $query->whereNull('dismissal_date')
                    ->orWhereDate('dismissal_date', '>=', (string) $dates->min());
            })
            ->orderByDesc('hire_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        return collect($references)->mapWithKeys(
            function (array $reference, int $key) use ($employees): array {
                $documentDate = $reference['date']->format('Y-m-d');
                $employee = $employees->get($reference['user_id'], collect())->first(
                    static fn (WorkforceEmployee $candidate): bool => $candidate->hire_date->toDateString() <= $documentDate
                        && ($candidate->dismissal_date === null || $candidate->dismissal_date->toDateString() >= $documentDate),
                );
                $fullName = trim((string) ($employee?->full_name ?? ''));

                return [$key => $fullName !== '' ? $fullName : null];
            },
        )->all();
    }

    public function orWhereEmployeeNameMatches(
        Builder $query,
        int $organizationId,
        string $search,
        string $userIdColumn,
        string $dateColumn,
    ): void {
        $normalizedSearch = mb_strtolower(trim($search));
        if ($normalizedSearch === '') {
            return;
        }

        $query->orWhereExists(function (QueryBuilder $employeeQuery) use (
            $dateColumn,
            $normalizedSearch,
            $organizationId,
            $userIdColumn,
        ): void {
            $employeeQuery
                ->selectRaw('1')
                ->from('workforce_employees as matched_employee')
                ->where('matched_employee.organization_id', $organizationId)
                ->whereNull('matched_employee.deleted_at')
                ->whereColumn('matched_employee.user_id', $userIdColumn)
                ->whereRaw("matched_employee.hire_date <= CAST({$dateColumn} AS date)")
                ->where(function (QueryBuilder $validityQuery) use ($dateColumn): void {
                    $validityQuery
                        ->whereNull('matched_employee.dismissal_date')
                        ->orWhereRaw("matched_employee.dismissal_date >= CAST({$dateColumn} AS date)");
                })
                ->whereRaw(
                    "LOWER(CONCAT_WS(' ', matched_employee.last_name, matched_employee.first_name, matched_employee.middle_name)) LIKE ?",
                    ['%'.$normalizedSearch.'%'],
                )
                ->whereNotExists(function (QueryBuilder $preferredEmployeeQuery) use (
                    $dateColumn,
                    $organizationId,
                    $userIdColumn,
                ): void {
                    $preferredEmployeeQuery
                        ->selectRaw('1')
                        ->from('workforce_employees as preferred_employee')
                        ->where('preferred_employee.organization_id', $organizationId)
                        ->whereNull('preferred_employee.deleted_at')
                        ->whereColumn('preferred_employee.user_id', $userIdColumn)
                        ->whereRaw("preferred_employee.hire_date <= CAST({$dateColumn} AS date)")
                        ->where(function (QueryBuilder $validityQuery) use ($dateColumn): void {
                            $validityQuery
                                ->whereNull('preferred_employee.dismissal_date')
                                ->orWhereRaw("preferred_employee.dismissal_date >= CAST({$dateColumn} AS date)");
                        })
                        ->where(function (QueryBuilder $priorityQuery): void {
                            $priorityQuery
                                ->whereColumn('preferred_employee.hire_date', '>', 'matched_employee.hire_date')
                                ->orWhere(function (QueryBuilder $sameHireDateQuery): void {
                                    $sameHireDateQuery
                                        ->whereColumn('preferred_employee.hire_date', 'matched_employee.hire_date')
                                        ->whereColumn('preferred_employee.id', '>', 'matched_employee.id');
                                });
                        });
                });
        });
    }
}
