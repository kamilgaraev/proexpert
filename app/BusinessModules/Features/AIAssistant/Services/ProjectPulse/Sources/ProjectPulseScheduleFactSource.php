<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseScheduleFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'schedule';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('schedule_tasks') || !$this->hasTable('project_schedules')) {
            return $this->empty();
        }

        $dateColumn = $this->hasColumn('schedule_tasks', 'planned_end_date') ? 'planned_end_date' : null;
        $dateColumn ??= $this->hasColumn('schedule_tasks', 'end_date') ? 'end_date' : null;
        $dateColumn ??= $this->hasColumn('schedule_tasks', 'finish_date') ? 'finish_date' : null;

        if ($dateColumn === null) {
            return $this->empty();
        }

        $query = $this->table($context, 'schedule_tasks')
            ->leftJoin('project_schedules', 'project_schedules.id', '=', 'schedule_tasks.schedule_id')
            ->leftJoin('projects', 'projects.id', '=', 'project_schedules.project_id')
            ->whereNotIn('schedule_tasks.status', ['done', 'completed', 'cancelled'])
            ->whereNotNull('schedule_tasks.' . $dateColumn)
            ->whereDate('schedule_tasks.' . $dateColumn, '<', $context->date->toDateString())
            ->when($context->projectId !== null, fn ($query) => $query->where('project_schedules.project_id', $context->projectId))
            ->limit($this->limit());

        return $query->get([
                'schedule_tasks.id',
                'schedule_tasks.schedule_id',
                'project_schedules.project_id',
                'schedule_tasks.name',
                'schedule_tasks.status',
                'schedule_tasks.' . $dateColumn . ' as deadline',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'schedule_task:' . $row->id . ':overdue',
                type: 'schedule_task',
                priority: 'critical',
                title: 'Задача графика просрочена',
                text: 'Задача графика "' . ($row->name ?? ('#' . $row->id)) . '" не закрыта в плановый срок.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'schedule_task',
                    'id' => (int) $row->id,
                    'label' => 'Задача графика #' . $row->id,
                    'route' => $this->scheduleRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                ],
                source: $this->key(),
                category: 'schedule',
                status: $row->status,
                nextAction: 'Обновить график, ответственного и следующий контрольный срок.',
                primaryAction: [
                    'label' => 'Открыть задачу',
                    'route' => $this->scheduleRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                    'permission' => 'schedule.view',
                ],
                deadline: (string) $row->deadline,
                ageDays: $this->ageDays($context, $row->deadline),
            ))->values();
    }

    private function scheduleRoute(?int $projectId, int $scheduleId): string
    {
        if ($projectId !== null) {
            return '/projects/' . $projectId . '/schedules/' . $scheduleId;
        }

        return '/schedules';
    }
}
