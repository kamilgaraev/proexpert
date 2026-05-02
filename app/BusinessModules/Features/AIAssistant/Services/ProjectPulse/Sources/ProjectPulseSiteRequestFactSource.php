<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseSiteRequestFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'site_requests';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('site_requests')) {
            return $this->empty();
        }

        $query = $this->table($context, 'site_requests')
            ->leftJoin('projects', 'projects.id', '=', 'site_requests.project_id')
            ->where(function ($query): void {
                if ($this->hasColumn('site_requests', 'assigned_to')) {
                    $query->whereNull('site_requests.assigned_to');
                }

                if ($this->hasColumn('site_requests', 'status')) {
                    $query->orWhereIn('site_requests.status', ['draft', 'new', 'pending', 'submitted']);
                }
            })
            ->limit($this->limit());

        return $query->get([
            'site_requests.id',
            'site_requests.project_id',
            'site_requests.title',
            'site_requests.status',
            'site_requests.priority',
            'site_requests.required_date',
            'site_requests.created_at',
            'projects.name as project_name',
        ])->map(fn ($row) => new ProjectPulseFact(
            id: 'site_request:' . $row->id . ':requires_reaction',
            type: 'site_request',
            priority: in_array($row->priority, ['urgent', 'high'], true) ? 'critical' : 'warning',
            title: 'Заявка с объекта требует реакции',
            text: 'Заявка "' . ($row->title ?? ('#' . $row->id)) . '" ожидает назначения или обработки.',
            projectId: $row->project_id !== null ? (int) $row->project_id : null,
            projectName: $row->project_name,
            relatedEntity: [
                'type' => 'site_request',
                'id' => (int) $row->id,
                'label' => 'Заявка #' . $row->id,
                'route' => '/site-requests/' . $row->id,
            ],
            occurredAt: $this->dateString($row->created_at),
            source: $this->key(),
            category: 'request',
            status: $row->status,
            nextAction: 'Назначить ответственного и зафиксировать срок реакции по заявке.',
            primaryAction: [
                'label' => 'Открыть заявку',
                'route' => '/site-requests/' . $row->id,
                'permission' => 'site_requests.view',
            ],
            deadline: $row->required_date !== null ? (string) $row->required_date : null,
            ageDays: $this->ageDays($context, $row->created_at),
        ))->values();
    }
}
