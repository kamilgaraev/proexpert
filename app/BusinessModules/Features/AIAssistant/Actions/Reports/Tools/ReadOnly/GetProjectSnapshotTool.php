<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GetProjectSnapshotTool extends AbstractReadOnlyTool
{
    public function getName(): string
    {
        return 'get_project_snapshot';
    }

    public function getDescription(): string
    {
        return 'Возвращает read-only сводку по проектам организации: карточка проекта, договоры, графики, закупочные заявки и ключевые счетчики.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer', 'description' => 'ID проекта'],
                'query' => ['type' => 'string', 'description' => 'Название, адрес или внешний код проекта'],
                'status' => ['type' => 'string', 'description' => 'Статус проекта'],
                'limit' => ['type' => 'integer', 'description' => 'Максимум проектов, от 1 до 30', 'default' => 10],
            ],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        unset($user);

        if (!$this->hasTable('projects')) {
            return $this->tableUnavailable('projects', 'projects');
        }

        $query = $this->withoutDeleted($this->orgTable('projects', $organization), 'projects');
        $projectId = $this->intArg($arguments, 'project_id');
        $search = $this->stringArg($arguments, 'query');
        $status = $this->stringArg($arguments, 'status');

        if ($projectId !== null) {
            $query->where('projects.id', $projectId);
        }

        if ($search !== null) {
            $query->where(function ($inner) use ($search): void {
                $inner->where('projects.name', 'ilike', "%{$search}%")
                    ->orWhere('projects.address', 'ilike', "%{$search}%");

                if ($this->hasColumn('projects', 'external_code')) {
                    $inner->orWhere('projects.external_code', 'ilike', "%{$search}%");
                }
            });
        }

        if ($status !== null) {
            $query->where('projects.status', $status);
        }

        $projects = $query
            ->select([
                'projects.id',
                'projects.name',
                'projects.address',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                'projects.customer',
                'projects.external_code',
            ])
            ->orderByDesc('projects.id')
            ->limit($this->limit($arguments))
            ->get();

        return [
            'status' => 'success',
            'domain' => 'projects',
            'filters' => [
                'project_id' => $projectId,
                'query' => $search,
                'status' => $status,
            ],
            'results' => $projects->map(fn (object $project): array => $this->projectSnapshot($project, $organization))->all(),
        ];
    }

    private function projectSnapshot(object $project, Organization $organization): array
    {
        $projectId = (int) $project->id;

        return [
            'project' => [
                'id' => $projectId,
                'name' => $project->name,
                'address' => $project->address,
                'status' => $project->status,
                'budget_amount' => $project->budget_amount !== null ? (float) $project->budget_amount : null,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'customer' => $project->customer,
                'external_code' => $project->external_code,
            ],
            'contracts' => $this->contractSummary($organization, $projectId),
            'schedule' => $this->scheduleSummary($organization, $projectId),
            'procurement' => $this->procurementSummary($organization, $projectId),
        ];
    }

    private function contractSummary(Organization $organization, int $projectId): array
    {
        if (!$this->hasTable('contracts')) {
            return ['count' => 0, 'total_amount' => 0.0];
        }

        $query = $this->withoutDeleted($this->orgTable('contracts', $organization), 'contracts')
            ->where('contracts.project_id', $projectId);

        return [
            'count' => (clone $query)->count(),
            'total_amount' => round((float) (clone $query)->sum('total_amount'), 2),
        ];
    }

    private function scheduleSummary(Organization $organization, int $projectId): array
    {
        if (!$this->hasTable('project_schedules')) {
            return ['schedules_count' => 0, 'tasks_count' => 0, 'overdue_tasks_count' => 0, 'critical_tasks_count' => 0];
        }

        $schedules = $this->withoutDeleted($this->orgTable('project_schedules', $organization), 'project_schedules')
            ->where('project_schedules.project_id', $projectId);
        $scheduleIds = (clone $schedules)->pluck('project_schedules.id')->all();

        if ($scheduleIds === [] || !$this->hasTable('schedule_tasks')) {
            return ['schedules_count' => count($scheduleIds), 'tasks_count' => 0, 'overdue_tasks_count' => 0, 'critical_tasks_count' => 0];
        }

        $tasks = $this->withoutDeleted($this->orgTable('schedule_tasks', $organization), 'schedule_tasks')
            ->whereIn('schedule_tasks.schedule_id', $scheduleIds);

        return [
            'schedules_count' => count($scheduleIds),
            'tasks_count' => (clone $tasks)->count(),
            'overdue_tasks_count' => (clone $tasks)
                ->whereDate('planned_end_date', '<', now()->toDateString())
                ->where('progress_percent', '<', 100)
                ->count(),
            'critical_tasks_count' => (clone $tasks)->where('is_critical', true)->count(),
        ];
    }

    private function procurementSummary(Organization $organization, int $projectId): array
    {
        if (!$this->hasTable('purchase_requests') || !$this->hasTable('site_requests')) {
            return ['requests_count' => 0, 'open_requests_count' => 0];
        }

        $query = $this->withoutDeleted($this->orgTable('purchase_requests', $organization), 'purchase_requests')
            ->join('site_requests', 'purchase_requests.site_request_id', '=', 'site_requests.id')
            ->where('site_requests.project_id', $projectId);

        return [
            'requests_count' => (clone $query)->count(),
            'open_requests_count' => (clone $query)->whereNotIn('purchase_requests.status', ['approved', 'rejected', 'cancelled'])->count(),
        ];
    }
}
