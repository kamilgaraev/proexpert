<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class WorkforceCapacityOptionsService
{
    public const TYPES = [
        'projects', 'departments', 'positions', 'employment_types', 'rate_types', 'currencies',
    ];

    public const LIMIT = 50;

    public function __construct(private ConnectionInterface $connection) {}

    public function summary(ReportScope $scope): array
    {
        $firstMonth = $this->connection->table('workforce_staff_units')
            ->where('organization_id', $scope->organizationId)
            ->whereNull('deleted_at')
            ->orderBy('valid_from')->value('valid_from');
        $currentMonth = now()->startOfMonth()->format('Y-m');
        $minimumMonth = is_string($firstMonth) ? substr($firstMonth, 0, 7) : null;
        if ($minimumMonth !== null && $minimumMonth > $currentMonth) {
            $minimumMonth = null;
        }

        return [
            'period' => [
                'min_month' => $minimumMonth,
                'max_month' => $currentMonth,
                'default_from' => $currentMonth,
                'default_to' => $currentMonth,
            ],
            'option_types' => self::TYPES,
            'option_limit' => self::LIMIT,
        ];
    }

    public function search(ReportScope $scope, string $type, ?string $search, int $page): array
    {
        if (! in_array($type, self::TYPES, true) || $page < 1) {
            throw new InvalidArgumentException('workforce_capacity_option_type_invalid');
        }
        $needle = trim((string) $search);
        $query = match ($type) {
            'projects' => $this->projects($scope, $needle),
            'departments' => $this->organizationDictionary($scope, 'workforce_departments', $needle),
            'positions' => $this->organizationDictionary($scope, 'workforce_positions', $needle),
            'employment_types' => $this->employmentTypes($scope, $needle),
            'rate_types' => $this->rates($scope, 'rate_type', $needle),
            'currencies' => $this->rates($scope, 'currency', $needle),
        };
        $rows = $query->offset(($page - 1) * self::LIMIT)->limit(self::LIMIT + 1)->get();
        $hasMore = $rows->count() > self::LIMIT;

        return [
            'type' => $type,
            'items' => $rows->take(self::LIMIT)->map(static fn (object $item): array => [
                'id' => is_numeric($item->id) ? (int) $item->id : (string) $item->id,
                'name' => (string) $item->name,
            ])->values()->all(),
            'has_more' => $hasMore,
            'page' => $page,
            'next_page' => $hasMore ? $page + 1 : null,
        ];
    }

    private function projects(ReportScope $scope, string $search): Builder
    {
        $query = $this->connection->table('projects')
            ->where('status', 'active')->where('is_archived', false)
            ->whereIn('id', $scope->projectIds)
            ->selectRaw('id AS id, name AS name');
        $this->applySearch($query, 'name', $search);

        return $query->orderBy('name')->orderBy('id');
    }

    private function organizationDictionary(
        ReportScope $scope,
        string $table,
        string $search,
    ): Builder {
        $query = $this->connection->table($table)
            ->where('organization_id', $scope->organizationId)
            ->where('is_active', true)->whereNull('deleted_at')
            ->selectRaw('id AS id, name AS name');
        $this->applySearch($query, 'name', $search);

        return $query->orderBy('name')->orderBy('id');
    }

    private function employmentTypes(ReportScope $scope, string $search): Builder
    {
        $query = $this->connection->table('workforce_employee_assignments as assignment')
            ->join('workforce_employees as employee', function ($join): void {
                $join->on('employee.id', '=', 'assignment.employee_id')
                    ->on('employee.organization_id', '=', 'assignment.organization_id');
            })
            ->where('assignment.organization_id', $scope->organizationId)
            ->whereIn('assignment.project_id', $scope->projectIds)
            ->where('assignment.status', 'active')->whereNull('assignment.deleted_at')
            ->whereNull('employee.deleted_at')
            ->selectRaw('employee.employment_status AS id, employee.employment_status AS name')
            ->distinct();
        $this->applySearch($query, 'employee.employment_status', $search);

        return $query->orderBy('employee.employment_status');
    }

    private function rates(ReportScope $scope, string $column, string $search): Builder
    {
        $query = $this->connection->table('time_tracking_labor_rate_versions as rate')
            ->join('workforce_employee_assignments as assignment', function ($join): void {
                $join->on('assignment.employee_id', '=', 'rate.employee_id')
                    ->on('assignment.organization_id', '=', 'rate.organization_id');
            })
            ->where('rate.organization_id', $scope->organizationId)
            ->where('rate.status', 'approved')->whereNotNull('rate.'.$column)
            ->whereIn('assignment.project_id', $scope->projectIds)
            ->selectRaw("rate.{$column} AS id, rate.{$column} AS name")->distinct();
        $this->applySearch($query, 'rate.'.$column, $search);

        return $query->orderBy('rate.'.$column);
    }

    private function applySearch(Builder $query, string $column, string $search): void
    {
        if ($search !== '') {
            $query->whereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
        }
    }
}
