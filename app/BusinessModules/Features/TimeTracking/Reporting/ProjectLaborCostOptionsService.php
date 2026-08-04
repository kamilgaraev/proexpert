<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class ProjectLaborCostOptionsService
{
    public const TYPES = ['employees', 'tasks', 'work_types', 'contractors'];

    public const LIMIT = 50;

    public function __construct(private ConnectionInterface $connection) {}

    /** @return array<string, mixed> */
    public function summary(int $organizationId, int $projectId): array
    {
        $period = $this->approvedEntries($organizationId, $projectId)
            ->selectRaw('MIN(entry.work_date) AS period_from, MAX(entry.work_date) AS period_to')
            ->first();

        return [
            'period' => [
                'min' => $period?->period_from === null ? null : (string) $period->period_from,
                'max' => $period?->period_to === null ? null : (string) $period->period_to,
            ],
            'statuses' => [['id' => 'approved']],
            'billable' => [
                ['id' => true],
                ['id' => false],
            ],
            'option_types' => self::TYPES,
            'option_limit' => self::LIMIT,
        ];
    }

    /** @return array{type:string,items:list<array{id:int,name:string}>,has_more:bool,page:int,next_page:?int} */
    public function search(
        int $organizationId,
        int $projectId,
        string $type,
        ?string $search,
        int $page,
    ): array {
        if (! in_array($type, self::TYPES, true) || $page < 1) {
            throw new InvalidArgumentException('project_labor_cost_option_type_invalid');
        }

        $needle = trim((string) $search);
        $query = match ($type) {
            'employees' => $this->employees($organizationId, $projectId, $needle),
            'tasks' => $this->tasks($organizationId, $projectId, $needle),
            'work_types' => $this->workTypes($organizationId, $projectId, $needle),
            'contractors' => $this->contractors($organizationId, $projectId, $needle),
        };
        $rows = $query
            ->offset(($page - 1) * self::LIMIT)
            ->limit(self::LIMIT + 1)
            ->get();
        $hasMore = $rows->count() > self::LIMIT;

        return [
            'type' => $type,
            'items' => $rows->take(self::LIMIT)->map(
                static fn (object $option): array => [
                    'id' => (int) $option->id,
                    'name' => (string) $option->name,
                ],
            )->values()->all(),
            'has_more' => $hasMore,
            'page' => $page,
            'next_page' => $hasMore ? $page + 1 : null,
        ];
    }

    private function approvedEntries(int $organizationId, int $projectId): Builder
    {
        return $this->connection->table('time_entries as entry')
            ->where('entry.organization_id', $organizationId)
            ->where('entry.project_id', $projectId)
            ->where('entry.status', 'approved')
            ->whereNull('entry.deleted_at');
    }

    private function employees(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->approvedEntries($organizationId, $projectId)
            ->join('workforce_employees as employee', function ($join): void {
                $join->on('employee.user_id', '=', 'entry.user_id')
                    ->on('employee.organization_id', '=', 'entry.organization_id');
            })
            ->whereNull('employee.deleted_at')
            ->selectRaw("employee.id, TRIM(CONCAT_WS(' ', employee.last_name, employee.first_name, employee.middle_name)) AS name")
            ->distinct();
        $this->applySearch($query, "CONCAT_WS(' ', employee.last_name, employee.first_name, employee.middle_name)", $search);

        return $query->orderBy('name')->orderBy('employee.id');
    }

    private function tasks(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->approvedEntries($organizationId, $projectId)
            ->join('schedule_tasks as task', function ($join): void {
                $join->on('task.id', '=', 'entry.task_id')
                    ->on('task.organization_id', '=', 'entry.organization_id');
            })
            ->join('project_schedules as schedule', function ($join): void {
                $join->on('schedule.id', '=', 'task.schedule_id')
                    ->on('schedule.organization_id', '=', 'entry.organization_id')
                    ->on('schedule.project_id', '=', 'entry.project_id');
            })
            ->select(['task.id', 'task.name'])
            ->distinct();
        $this->applySearch($query, 'task.name', $search);

        return $query->orderBy('task.name')->orderBy('task.id');
    }

    private function workTypes(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->approvedEntries($organizationId, $projectId)
            ->join('work_types as work_type', function ($join): void {
                $join->on('work_type.id', '=', 'entry.work_type_id')
                    ->on('work_type.organization_id', '=', 'entry.organization_id');
            })
            ->select(['work_type.id', 'work_type.name'])
            ->distinct();
        $this->applySearch($query, 'work_type.name', $search);

        return $query->orderBy('work_type.name')->orderBy('work_type.id');
    }

    private function contractors(int $organizationId, int $projectId, string $search): Builder
    {
        $query = $this->connection->table('completed_works as work')
            ->join('contractors as contractor', function ($join): void {
                $join->on('contractor.id', '=', 'work.contractor_id')
                    ->on('contractor.organization_id', '=', 'work.organization_id');
            })
            ->where('work.organization_id', $organizationId)
            ->where('work.project_id', $projectId)
            ->where('work.status', 'confirmed')
            ->whereNull('work.deleted_at')
            ->select(['contractor.id', 'contractor.name'])
            ->distinct();
        $this->applySearch($query, 'contractor.name', $search);

        return $query->orderBy('contractor.name')->orderBy('contractor.id');
    }

    private function applySearch(Builder $query, string $expression, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->whereRaw("LOWER({$expression}) LIKE ?", ['%'.mb_strtolower($search).'%']);
    }
}
