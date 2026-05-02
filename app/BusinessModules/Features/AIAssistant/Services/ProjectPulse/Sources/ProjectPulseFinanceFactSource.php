<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseFinanceFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'finance';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->paymentDocuments($context))
            ->merge($this->payments($context))
            ->values();
    }

    private function paymentDocuments(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('payment_documents')) {
            return $this->empty();
        }

        foreach (['status', 'amount', 'due_date'] as $requiredColumn) {
            if (!$this->hasColumn('payment_documents', $requiredColumn)) {
                return $this->empty();
            }
        }

        $columns = [
            'payment_documents.id',
            'payment_documents.status',
            'payment_documents.amount',
            'payment_documents.due_date',
            'payment_documents.created_at',
        ];

        if ($this->hasColumn('payment_documents', 'project_id')) {
            $columns[] = 'payment_documents.project_id';
        }

        return $this->table($context, 'payment_documents')
            ->whereIn('payment_documents.status', ['pending', 'approval_pending', 'waiting_approval', 'overdue'])
            ->limit($this->limit())
            ->get($columns)
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'payment_document:' . $row->id . ':requires_action',
                type: 'payment_document',
                priority: $row->due_date !== null && (string) $row->due_date < $context->date->toDateString() ? 'critical' : 'warning',
                title: 'Платежный документ требует решения',
                text: 'Платежный документ ожидает согласования или оплаты.',
                projectId: isset($row->project_id) && $row->project_id !== null ? (int) $row->project_id : null,
                projectName: isset($row->project_id) && $row->project_id !== null ? $this->projectName((int) $row->project_id) : null,
                relatedEntity: [
                    'type' => 'payment_document',
                    'id' => (int) $row->id,
                    'label' => 'Платежный документ #' . $row->id,
                    'route' => '/payments/requests',
                ],
                amount: $row->amount !== null ? (float) $row->amount : null,
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'finance',
                status: $row->status,
                nextAction: 'Проверить платежный документ и принять решение по оплате.',
                primaryAction: [
                    'label' => 'Открыть платеж',
                    'route' => '/payments/requests',
                    'permission' => 'payments.view',
                ],
                deadline: $row->due_date !== null ? (string) $row->due_date : null,
                ageDays: $this->ageDays($context, $row->created_at),
            ))->values();
    }

    private function payments(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('payments') || !$this->hasColumn('payments', 'organization_id') || !$this->hasColumn('payments', 'amount')) {
            return $this->empty();
        }

        $columns = ['payments.id', 'payments.amount', 'payments.created_at'];

        if ($this->hasColumn('payments', 'project_id')) {
            $columns[] = 'payments.project_id';
        }

        return $this->table($context, 'payments')
            ->whereBetween('payments.created_at', [$context->from, $context->to])
            ->limit($this->limit())
            ->get($columns)
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'payment:' . $row->id,
                type: 'payment',
                priority: 'info',
                title: 'Зафиксирована оплата',
                text: 'В системе отражена оплата по проектному контуру.',
                projectId: isset($row->project_id) && $row->project_id !== null ? (int) $row->project_id : null,
                projectName: isset($row->project_id) && $row->project_id !== null ? $this->projectName((int) $row->project_id) : null,
                relatedEntity: [
                    'type' => 'payment',
                    'id' => (int) $row->id,
                    'label' => 'Оплата #' . $row->id,
                    'route' => '/payments/transactions',
                ],
                amount: (float) $row->amount,
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'finance',
                status: 'paid',
                nextAction: 'Сверить оплату с выполнением и закрывающими документами.',
            ))->values();
    }
}
