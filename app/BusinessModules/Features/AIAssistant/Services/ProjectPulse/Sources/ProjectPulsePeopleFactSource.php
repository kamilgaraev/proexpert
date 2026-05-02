<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulsePeopleFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'people';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->siteRequestsWithoutAssignee($context))
            ->merge($this->purchaseRequestsWithoutAssignee($context))
            ->values();
    }

    private function siteRequestsWithoutAssignee(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('site_requests') || !$this->hasColumn('site_requests', 'assigned_to')) {
            return $this->empty();
        }

        return $this->table($context, 'site_requests')
            ->whereNull('site_requests.assigned_to')
            ->whereNotIn('site_requests.status', ['completed', 'closed', 'cancelled'])
            ->limit($this->limit())
            ->get(['site_requests.id', 'site_requests.project_id', 'site_requests.title', 'site_requests.status', 'site_requests.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'people:site_request:' . $row->id . ':no_assignee',
                type: 'assignment',
                priority: 'warning',
                title: 'Нет ответственного по заявке',
                text: 'По заявке "' . ($row->title ?? ('#' . $row->id)) . '" не назначен ответственный.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $this->projectName($row->project_id !== null ? (int) $row->project_id : null),
                relatedEntity: [
                    'type' => 'site_request',
                    'id' => (int) $row->id,
                    'label' => 'Заявка #' . $row->id,
                    'route' => '/site-requests/' . $row->id,
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'people',
                status: $row->status,
                nextAction: 'Назначить ответственного исполнителя по заявке.',
                primaryAction: [
                    'label' => 'Назначить ответственного',
                    'route' => '/site-requests/' . $row->id,
                    'permission' => 'site_requests.update',
                ],
                ageDays: $this->ageDays($context, $row->created_at),
            ))->values();
    }

    private function purchaseRequestsWithoutAssignee(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('purchase_requests') || !$this->hasColumn('purchase_requests', 'assigned_to')) {
            return $this->empty();
        }

        return $this->table($context, 'purchase_requests')
            ->whereNull('purchase_requests.assigned_to')
            ->whereNotIn('purchase_requests.status', ['completed', 'closed', 'cancelled'])
            ->limit($this->limit())
            ->get(['purchase_requests.id', 'purchase_requests.request_number', 'purchase_requests.status', 'purchase_requests.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'people:purchase_request:' . $row->id . ':no_assignee',
                type: 'assignment',
                priority: 'warning',
                title: 'Нет ответственного по закупке',
                text: 'По закупочной заявке ' . $row->request_number . ' не назначен ответственный.',
                relatedEntity: [
                    'type' => 'purchase_request',
                    'id' => (int) $row->id,
                    'label' => 'Заявка на закупку ' . $row->request_number,
                    'route' => '/procurement/purchase-requests/' . $row->id,
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'people',
                status: $row->status,
                nextAction: 'Назначить ответственного за закупочную заявку.',
                ageDays: $this->ageDays($context, $row->created_at),
            ))->values();
    }
}
