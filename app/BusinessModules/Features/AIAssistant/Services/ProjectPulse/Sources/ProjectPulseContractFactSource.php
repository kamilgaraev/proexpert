<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseContractFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'contracts';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->contracts($context))
            ->merge($this->performanceActs($context))
            ->values();
    }

    private function contracts(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('contracts') || !$this->hasColumn('contracts', 'end_date')) {
            return $this->empty();
        }

        return $this->table($context, 'contracts')
            ->whereNotIn('contracts.status', ['closed', 'completed', 'cancelled'])
            ->whereNotNull('contracts.end_date')
            ->whereDate('contracts.end_date', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get(['contracts.id', 'contracts.project_id', 'contracts.number', 'contracts.status', 'contracts.total_amount', 'contracts.end_date'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'contract:' . $row->id . ':expired',
                type: 'contract',
                priority: 'critical',
                title: 'Договор просрочен',
                text: 'Договор ' . ($row->number ?? ('#' . $row->id)) . ' не закрыт после даты окончания.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $this->projectName($row->project_id !== null ? (int) $row->project_id : null),
                relatedEntity: [
                    'type' => 'contract',
                    'id' => (int) $row->id,
                    'label' => 'Договор ' . ($row->number ?? ('#' . $row->id)),
                    'route' => '/contracts/' . $row->id,
                ],
                amount: $row->total_amount !== null ? (float) $row->total_amount : null,
                source: $this->key(),
                category: 'contract',
                status: $row->status,
                nextAction: 'Проверить статус договора, закрывающие документы и продление срока.',
                primaryAction: [
                    'label' => 'Открыть договор',
                    'route' => '/contracts/' . $row->id,
                    'permission' => 'contracts.view',
                ],
                deadline: (string) $row->end_date,
                ageDays: $this->ageDays($context, $row->end_date),
            ))->values();
    }

    private function performanceActs(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('contract_performance_acts') || !$this->hasColumn('contract_performance_acts', 'status')) {
            return $this->empty();
        }

        $amountColumn = $this->hasColumn('contract_performance_acts', 'total_amount') ? 'total_amount' : 'amount';
        $columns = [
            'contract_performance_acts.id',
            'contract_performance_acts.status',
            'contract_performance_acts.' . $amountColumn . ' as amount',
            'contract_performance_acts.created_at',
        ];

        if ($this->hasColumn('contract_performance_acts', 'project_id')) {
            $columns[] = 'contract_performance_acts.project_id';
        }

        return $this->table($context, 'contract_performance_acts')
            ->whereIn('contract_performance_acts.status', ['draft', 'pending', 'approval_pending', 'waiting_signature'])
            ->limit($this->limit())
            ->get($columns)
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'contract_performance_act:' . $row->id . ':pending',
                type: 'contract_performance_act',
                priority: 'warning',
                title: 'Акт по договору ожидает действия',
                text: 'Акт по договору ожидает согласования, подписания или уточнения.',
                projectId: isset($row->project_id) && $row->project_id !== null ? (int) $row->project_id : null,
                projectName: isset($row->project_id) && $row->project_id !== null ? $this->projectName((int) $row->project_id) : null,
                relatedEntity: [
                    'type' => 'contract_performance_act',
                    'id' => (int) $row->id,
                    'label' => 'Акт #' . $row->id,
                    'route' => '/reports/act-reports',
                ],
                amount: $row->amount !== null ? (float) $row->amount : null,
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'contract',
                status: $row->status,
                nextAction: 'Завершить согласование или подписание акта.',
            ))->values();
    }
}
