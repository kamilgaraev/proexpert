<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectPulseFactCollector
{
    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->collectProjectFacts($context))
            ->merge($this->collectSiteRequestFacts($context))
            ->merge($this->collectCompletedWorkFacts($context))
            ->merge($this->collectPaymentFacts($context));
    }

    public function metrics(ProjectPulseContext $context, Collection $facts): array
    {
        $projectsQuery = $this->projectsQuery($context);

        return [
            [
                'key' => 'active_projects',
                'label' => 'Активные проекты',
                'value' => (clone $projectsQuery)->where('status', 'active')->count(),
                'tone' => 'primary',
            ],
            [
                'key' => 'critical_facts',
                'label' => 'Критичные события',
                'value' => $facts->where('priority', 'critical')->count(),
                'tone' => 'critical',
            ],
            [
                'key' => 'warning_facts',
                'label' => 'Требуют внимания',
                'value' => $facts->where('priority', 'warning')->count(),
                'tone' => 'warning',
            ],
            [
                'key' => 'daily_activity',
                'label' => 'События за период',
                'value' => $facts->whereNotNull('occurredAt')->count(),
                'tone' => 'neutral',
            ],
        ];
    }

    public function finance(ProjectPulseContext $context): array
    {
        $performedAmount = 0.0;
        $paidAmount = 0.0;

        if (Schema::hasTable('completed_works')) {
            $performedAmount = (float) $this->scopedTable($context, 'completed_works')
                ->whereBetween('completion_date', [$context->from->toDateString(), $context->to->toDateString()])
                ->sum('total_amount');
        }

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'organization_id')) {
            $paidAmount = (float) $this->scopedTable($context, 'payments')
                ->whereBetween('created_at', [$context->from, $context->to])
                ->sum('amount');
        }

        $deviation = max($performedAmount - $paidAmount, 0);

        return [
            'performed_amount' => $performedAmount,
            'paid_amount' => $paidAmount,
            'pending_acts_amount' => 0.0,
            'deviation_items' => $deviation > 0 ? [[
                'title' => 'Выполнение опережает оплату',
                'amount' => $deviation,
                'status' => $deviation > 100000 ? 'warning' : 'info',
            ]] : [],
        ];
    }

    private function collectProjectFacts(ProjectPulseContext $context): Collection
    {
        return $this->projectsQuery($context)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $context->date->toDateString())
            ->limit(20)
            ->get(['id', 'name', 'end_date'])
            ->map(fn (Project $project) => new ProjectPulseFact(
                id: 'project_deadline:' . $project->id,
                type: 'schedule',
                priority: 'critical',
                title: 'Срок проекта истек',
                text: 'Проект «' . $project->name . '» остается активным после плановой даты завершения.',
                projectId: (int) $project->id,
                projectName: (string) $project->name,
                relatedEntity: [
                    'type' => 'project',
                    'id' => (int) $project->id,
                    'label' => 'Проект #' . $project->id,
                    'route' => '/projects/' . $project->id,
                ],
            ));
    }

    private function collectSiteRequestFacts(ProjectPulseContext $context): Collection
    {
        if (!Schema::hasTable('site_requests')) {
            return collect();
        }

        return $this->scopedTable($context, 'site_requests')
            ->leftJoin('projects', 'projects.id', '=', 'site_requests.project_id')
            ->whereNull('site_requests.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('site_requests.assigned_to')
                    ->orWhereIn('site_requests.status', ['draft', 'new', 'pending']);
            })
            ->whereBetween('site_requests.created_at', [$context->from->subDays(7), $context->to])
            ->limit(30)
            ->get([
                'site_requests.id',
                'site_requests.project_id',
                'site_requests.title',
                'site_requests.priority',
                'site_requests.created_at',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'site_request:' . $row->id,
                type: 'site_request',
                priority: in_array($row->priority, ['urgent', 'high'], true) ? 'critical' : 'warning',
                title: 'Заявка требует реакции',
                text: 'Заявка «' . $row->title . '» ожидает назначения или обработки.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'site_request',
                    'id' => (int) $row->id,
                    'label' => 'Заявка #' . $row->id,
                    'route' => '/site-requests/' . $row->id,
                ],
                occurredAt: (string) $row->created_at,
            ));
    }

    private function collectCompletedWorkFacts(ProjectPulseContext $context): Collection
    {
        if (!Schema::hasTable('completed_works')) {
            return collect();
        }

        return $this->scopedTable($context, 'completed_works')
            ->leftJoin('projects', 'projects.id', '=', 'completed_works.project_id')
            ->whereNull('completed_works.deleted_at')
            ->whereBetween('completed_works.completion_date', [$context->from->toDateString(), $context->to->toDateString()])
            ->limit(30)
            ->get([
                'completed_works.id',
                'completed_works.project_id',
                'completed_works.total_amount',
                'completed_works.completion_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'completed_work:' . $row->id,
                type: 'completed_work',
                priority: 'info',
                title: 'Добавлены выполненные работы',
                text: 'Зафиксированы выполненные работы по проекту «' . $row->project_name . '».',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'completed_work',
                    'id' => (int) $row->id,
                    'label' => 'Работы #' . $row->id,
                    'route' => '/completed-works/' . $row->id,
                ],
                amount: $row->total_amount !== null ? (float) $row->total_amount : null,
                occurredAt: (string) $row->completion_date,
            ));
    }

    private function collectPaymentFacts(ProjectPulseContext $context): Collection
    {
        if (!Schema::hasTable('payments') || !Schema::hasColumn('payments', 'organization_id')) {
            return collect();
        }

        $columns = ['id', 'amount', 'created_at'];
        if (Schema::hasColumn('payments', 'project_id')) {
            $columns[] = 'project_id';
        }

        return $this->scopedTable($context, 'payments')
            ->whereBetween('created_at', [$context->from, $context->to])
            ->limit(20)
            ->get($columns)
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'payment:' . $row->id,
                type: 'payment',
                priority: 'info',
                title: 'Зафиксирована оплата',
                text: 'По проекту отражена оплата.',
                projectId: isset($row->project_id) ? (int) $row->project_id : null,
                relatedEntity: [
                    'type' => 'payment',
                    'id' => (int) $row->id,
                    'label' => 'Оплата #' . $row->id,
                    'route' => '/payments/transactions/' . $row->id,
                ],
                amount: (float) $row->amount,
                occurredAt: (string) $row->created_at,
            ));
    }

    private function projectsQuery(ProjectPulseContext $context)
    {
        return Project::query()
            ->where('organization_id', $context->organizationId)
            ->when($context->projectId !== null, fn ($query) => $query->whereKey($context->projectId));
    }

    private function scopedTable(ProjectPulseContext $context, string $table)
    {
        return DB::table($table)
            ->where($table . '.organization_id', $context->organizationId)
            ->when($context->projectId !== null && Schema::hasColumn($table, 'project_id'), function ($query) use ($context, $table): void {
                $query->where($table . '.project_id', $context->projectId);
            });
    }
}
