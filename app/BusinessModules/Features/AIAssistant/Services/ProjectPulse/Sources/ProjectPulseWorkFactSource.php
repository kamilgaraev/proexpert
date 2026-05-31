<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseWorkFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'work';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('completed_works')) {
            return $this->empty();
        }

        return $this->table($context, 'completed_works')
            ->leftJoin('projects', 'projects.id', '=', 'completed_works.project_id')
            ->whereBetween('completed_works.completion_date', [$context->from->toDateString(), $context->to->toDateString()])
            ->limit($this->limit())
            ->get([
                'completed_works.id',
                'completed_works.project_id',
                'completed_works.total_amount',
                'completed_works.status',
                'completed_works.completion_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'completed_work:' . $row->id,
                type: 'completed_work',
                priority: 'info',
                title: 'Зафиксированы выполненные работы',
                text: 'По проекту "' . ($row->project_name ?? ('#' . $row->project_id)) . '" зафиксированы выполненные работы.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'completed_work',
                    'id' => (int) $row->id,
                    'label' => 'Выполненные работы #' . $row->id,
                    'route' => '/workflow/completed-works',
                ],
                amount: $row->total_amount !== null ? (float) $row->total_amount : null,
                occurredAt: $this->dateString($row->completion_date),
                source: $this->key(),
                category: 'work',
                status: $row->status,
                nextAction: 'Сверить выполнение с актами, оплатой и графиком.',
            ))->values();
    }
}
