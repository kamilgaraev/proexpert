<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class PayrollReadinessOptionsService
{
    public const TYPES = ['periods', 'employees', 'issue_codes', 'source_types'];

    public const LIMIT = 50;

    public function __construct(private ConnectionInterface $connection) {}

    public function summary(int $organizationId, int $projectId): array
    {
        $periods = $this->connection->table('workforce_payroll_periods as period')
            ->where('period.organization_id', $organizationId)
            ->where('period.project_id', $projectId)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('workforce_payroll_calculation_versions as version')
                    ->whereColumn('version.organization_id', 'period.organization_id')
                    ->whereColumn('version.payroll_period_id', 'period.id');
            })
            ->orderByDesc('period.period_start')->orderByDesc('period.id')
            ->limit(100)
            ->get(['period.id', 'period.period_start', 'period.period_end', 'period.status'])
            ->map(static fn (object $period): array => [
                'id' => (int) $period->id,
                'label' => (string) $period->period_start.' — '.(string) $period->period_end,
                'period_start' => (string) $period->period_start,
                'period_end' => (string) $period->period_end,
                'status' => (string) $period->status,
            ])->all();

        return [
            'periods' => $periods,
            'severities' => [['id' => 'blocking'], ['id' => 'warning']],
            'statuses' => [['id' => 'built'], ['id' => 'validated'], ['id' => 'locked']],
            'option_types' => self::TYPES,
            'option_limit' => self::LIMIT,
        ];
    }

    public function search(int $organizationId, int $projectId, string $type, ?string $search, int $page): array
    {
        if (! in_array($type, self::TYPES, true) || $page < 1) {
            throw new InvalidArgumentException('payroll_readiness_option_type_invalid');
        }
        $needle = trim((string) $search);
        $query = match ($type) {
            'periods' => $this->periods($organizationId, $projectId, $needle),
            'employees' => $this->employees($organizationId, $projectId, $needle),
            'issue_codes' => $this->issueCodes($organizationId, $projectId, $needle),
            'source_types' => $this->sourceTypes($organizationId, $projectId, $needle),
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

    private function scopedSourceRows(int $organizationId, int $projectId): Builder
    {
        return $this->connection->table('workforce_payroll_calculation_source_rows as source')
            ->join('workforce_payroll_calculation_versions as version', function ($join): void {
                $join->on('version.id', '=', 'source.calculation_version_id')
                    ->on('version.organization_id', '=', 'source.organization_id');
            })
            ->join('workforce_payroll_periods as period', function ($join): void {
                $join->on('period.id', '=', 'version.payroll_period_id')
                    ->on('period.organization_id', '=', 'version.organization_id');
            })
            ->where('source.organization_id', $organizationId)
            ->where('period.project_id', $projectId);
    }

    private function periods(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->connection->table('workforce_payroll_periods as period')
            ->where('period.organization_id', $organizationId)
            ->where('period.project_id', $projectId)
            ->whereExists(function (Builder $exists): void {
                $exists->selectRaw('1')->from('workforce_payroll_calculation_versions as version')
                    ->whereColumn('version.organization_id', 'period.organization_id')
                    ->whereColumn('version.payroll_period_id', 'period.id');
            })
            ->selectRaw("period.id AS id, period.period_start || ' — ' || period.period_end AS name");
        if ($search !== '') {
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('period.period_start', 'like', '%'.$search.'%')
                    ->orWhere('period.period_end', 'like', '%'.$search.'%');
            });
        }

        return $query->orderByDesc('period.period_start')->orderByDesc('period.id');
    }

    private function employees(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->scopedSourceRows($organizationId, $projectId)
            ->selectRaw('source.employee_id AS id, source.employee_name AS name')->distinct();
        $this->applySearch($query, 'source.employee_name', $search);

        return $query->orderBy('name')->orderBy('source.employee_id');
    }

    private function sourceTypes(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->scopedSourceRows($organizationId, $projectId)
            ->selectRaw('source.source_type AS id, source.source_type AS name')->distinct();
        $this->applySearch($query, 'source.source_type', $search);

        return $query->orderBy('source.source_type');
    }

    private function issueCodes(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->connection->table('workforce_payroll_calculation_issues as issue')
            ->join('workforce_payroll_calculation_versions as version', function ($join): void {
                $join->on('version.id', '=', 'issue.calculation_version_id')
                    ->on('version.organization_id', '=', 'issue.organization_id');
            })
            ->join('workforce_payroll_periods as period', function ($join): void {
                $join->on('period.id', '=', 'version.payroll_period_id')
                    ->on('period.organization_id', '=', 'version.organization_id');
            })
            ->where('issue.organization_id', $organizationId)->where('period.project_id', $projectId)
            ->selectRaw('issue.issue_code AS id, issue.issue_code AS name')->distinct();
        $this->applySearch($query, 'issue.issue_code', $search);

        return $query->orderBy('issue.issue_code');
    }

    private function applySearch(Builder $query, string $column, string $search): void
    {
        if ($search !== '') {
            $query->whereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
        }
    }
}
