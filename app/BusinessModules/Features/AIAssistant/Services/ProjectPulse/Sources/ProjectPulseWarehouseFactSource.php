<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseWarehouseFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'warehouse';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->lowBalances($context))
            ->merge($this->projectAllocations($context))
            ->merge($this->overdueTasks($context))
            ->values();
    }

    private function lowBalances(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('warehouse_balances')) {
            return $this->empty();
        }

        $quantityColumn = $this->hasColumn('warehouse_balances', 'available_quantity') ? 'available_quantity' : null;
        $quantityColumn ??= $this->hasColumn('warehouse_balances', 'quantity') ? 'quantity' : null;

        if ($quantityColumn === null) {
            return $this->empty();
        }

        return $this->table($context, 'warehouse_balances')
            ->where('warehouse_balances.' . $quantityColumn, '<=', 0)
            ->limit($this->limit())
            ->get(['warehouse_balances.id', 'warehouse_balances.' . $quantityColumn . ' as quantity', 'warehouse_balances.updated_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'warehouse_balance:' . $row->id . ':empty',
                type: 'warehouse_balance',
                priority: 'warning',
                title: 'По складской позиции нет остатка',
                text: 'Складская позиция требует проверки остатка или пополнения.',
                relatedEntity: [
                    'type' => 'warehouse_balance',
                    'id' => (int) $row->id,
                    'label' => 'Остаток #' . $row->id,
                    'route' => '/warehouse',
                ],
                amount: $row->quantity !== null ? (float) $row->quantity : null,
                occurredAt: $this->dateString($row->updated_at),
                source: $this->key(),
                category: 'warehouse',
                status: 'empty',
                nextAction: 'Проверить остаток и оформить пополнение или перемещение.',
                primaryAction: [
                    'label' => 'Открыть остаток',
                    'route' => '/warehouse',
                    'permission' => 'warehouse.view',
                ],
            ))->values();
    }

    private function projectAllocations(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('warehouse_project_allocations')) {
            return $this->empty();
        }

        $columns = [
            'warehouse_project_allocations.id',
            'warehouse_project_allocations.project_id',
            'warehouse_project_allocations.created_at',
        ];

        if ($this->hasColumn('warehouse_project_allocations', 'status')) {
            $columns[] = 'warehouse_project_allocations.status';
        }

        $query = $this->table($context, 'warehouse_project_allocations')
            ->limit($this->limit());

        if ($this->hasColumn('warehouse_project_allocations', 'status')) {
            $query->whereIn('warehouse_project_allocations.status', ['reserved', 'allocated', 'pending']);
        }

        return $query->get($columns)
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'warehouse_allocation:' . $row->id . ':pending',
                type: 'warehouse_allocation',
                priority: 'info',
                title: 'Есть складской резерв по проекту',
                text: 'По проекту есть складской резерв или распределение, которое нужно сверить с выдачей.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $this->projectName($row->project_id !== null ? (int) $row->project_id : null),
                relatedEntity: [
                    'type' => 'warehouse_allocation',
                    'id' => (int) $row->id,
                    'label' => 'Складской резерв #' . $row->id,
                    'route' => '/warehouse',
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'warehouse',
                status: $row->status ?? 'allocated',
                nextAction: 'Сверить резерв с фактической выдачей материалов на проект.',
            ))->values();
    }

    private function overdueTasks(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('warehouse_tasks') || !$this->hasColumn('warehouse_tasks', 'due_at')) {
            return $this->empty();
        }

        return $this->table($context, 'warehouse_tasks')
            ->whereNotIn('warehouse_tasks.status', ['done', 'completed', 'cancelled'])
            ->whereDate('warehouse_tasks.due_at', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get(['warehouse_tasks.id', 'warehouse_tasks.status', 'warehouse_tasks.due_at', 'warehouse_tasks.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'warehouse_task:' . $row->id . ':overdue',
                type: 'warehouse_task',
                priority: 'warning',
                title: 'Складская задача просрочена',
                text: 'Складская задача не закрыта к плановому сроку.',
                relatedEntity: [
                    'type' => 'warehouse_task',
                    'id' => (int) $row->id,
                    'label' => 'Складская задача #' . $row->id,
                    'route' => '/warehouse',
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'warehouse',
                status: $row->status,
                nextAction: 'Проверить исполнителя складской задачи и обновить срок.',
                deadline: (string) $row->due_at,
                ageDays: $this->ageDays($context, $row->due_at),
            ))->values();
    }
}
