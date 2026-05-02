<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseProjectFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'project';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('projects')) {
            return $this->empty();
        }

        $facts = collect();

        if ($this->hasColumn('projects', 'end_date')) {
            $facts = $facts->merge($this->table($context, 'projects')
                ->where('projects.status', 'active')
                ->whereNotNull('projects.end_date')
                ->whereDate('projects.end_date', '<', $context->date->toDateString())
                ->limit($this->limit())
                ->get(['projects.id', 'projects.name', 'projects.end_date'])
                ->map(fn ($row) => new ProjectPulseFact(
                    id: 'project:' . $row->id . ':deadline_overdue',
                    type: 'project_deadline',
                    priority: 'critical',
                    title: 'Срок проекта истек',
                    text: 'Проект "' . $row->name . '" остается активным после плановой даты завершения.',
                    projectId: (int) $row->id,
                    projectName: (string) $row->name,
                    relatedEntity: $this->projectRoute((int) $row->id),
                    source: $this->key(),
                    category: 'project',
                    status: 'active',
                    nextAction: 'Проверить план завершения проекта и зафиксировать новый контрольный срок.',
                    primaryAction: [
                        'label' => 'Открыть проект',
                        'route' => '/projects/' . $row->id,
                        'permission' => 'projects.view',
                    ],
                    deadline: (string) $row->end_date,
                    ageDays: $this->ageDays($context, $row->end_date),
                )));

            $facts = $facts->merge($this->table($context, 'projects')
                ->where('projects.status', 'active')
                ->whereNotNull('projects.end_date')
                ->whereDate('projects.end_date', '>=', $context->date->toDateString())
                ->whereDate('projects.end_date', '<=', $context->date->copy()->addDays(7)->toDateString())
                ->limit($this->limit())
                ->get(['projects.id', 'projects.name', 'projects.end_date'])
                ->map(fn ($row) => new ProjectPulseFact(
                    id: 'project:' . $row->id . ':deadline_soon',
                    type: 'project_deadline',
                    priority: 'warning',
                    title: 'Проект близок к плановой дате завершения',
                    text: 'До планового завершения проекта "' . $row->name . '" осталось не больше семи дней.',
                    projectId: (int) $row->id,
                    projectName: (string) $row->name,
                    relatedEntity: $this->projectRoute((int) $row->id),
                    source: $this->key(),
                    category: 'project',
                    status: 'active',
                    nextAction: 'Сверить готовность работ, документов и ответственных до даты завершения.',
                    primaryAction: [
                        'label' => 'Открыть проект',
                        'route' => '/projects/' . $row->id,
                        'permission' => 'projects.view',
                    ],
                    deadline: (string) $row->end_date,
                )));
        }

        return $facts->values();
    }
}
