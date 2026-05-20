<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

use function trans_message;

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

        return $this->overdueTasks($context)
            ->merge($this->overdueWorkConstraints($context))
            ->take($this->limit())
            ->values();
    }

    private function overdueTasks(ProjectPulseContext $context): Collection
    {
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
                title: trans_message('ai_assistant.project_pulse.schedule.task_overdue_title'),
                text: trans_message('ai_assistant.project_pulse.schedule.task_overdue_text', [
                    'name' => (string) ($row->name ?? ('#' . $row->id)),
                ]),
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'schedule_task',
                    'id' => (int) $row->id,
                    'label' => trans_message('ai_assistant.project_pulse.schedule.task_label', [
                        'id' => (string) $row->id,
                    ]),
                    'route' => $this->scheduleRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                ],
                source: $this->key(),
                category: 'schedule',
                status: $row->status,
                nextAction: trans_message('ai_assistant.project_pulse.schedule.task_next_action'),
                primaryAction: [
                    'label' => trans_message('ai_assistant.project_pulse.schedule.task_primary_action'),
                    'route' => $this->scheduleRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                    'permission' => 'schedule.view',
                ],
                deadline: (string) $row->deadline,
                ageDays: $this->ageDays($context, $row->deadline),
            ))->values();
    }

    private function overdueWorkConstraints(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('work_constraints') || !$this->hasTable('lookahead_plan_tasks')) {
            return $this->empty();
        }

        return $this->table($context, 'work_constraints')
            ->leftJoin('project_schedules', 'project_schedules.id', '=', 'work_constraints.schedule_id')
            ->leftJoin('projects', 'projects.id', '=', 'work_constraints.project_id')
            ->leftJoin('lookahead_plan_tasks', 'lookahead_plan_tasks.id', '=', 'work_constraints.lookahead_plan_task_id')
            ->leftJoin('lookahead_plans', 'lookahead_plans.id', '=', 'lookahead_plan_tasks.lookahead_plan_id')
            ->leftJoin('schedule_tasks', 'schedule_tasks.id', '=', 'work_constraints.schedule_task_id')
            ->where('work_constraints.status', 'open')
            ->whereNotNull('work_constraints.due_date')
            ->whereDate('work_constraints.due_date', '<', $context->date->toDateString())
            ->when($context->projectId !== null, fn ($query) => $query->where('work_constraints.project_id', $context->projectId))
            ->orderByRaw("case when work_constraints.severity = 'hard' then 0 else 1 end")
            ->orderBy('work_constraints.due_date')
            ->limit($this->limit())
            ->get([
                'work_constraints.id',
                'work_constraints.project_id',
                'work_constraints.schedule_id',
                'work_constraints.schedule_task_id',
                'work_constraints.constraint_type',
                'work_constraints.title',
                'work_constraints.severity',
                'work_constraints.status',
                'work_constraints.due_date',
                'projects.name as project_name',
                'schedule_tasks.name as task_name',
                'lookahead_plans.id as lookahead_plan_id',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'work_constraint:' . $row->id . ':overdue',
                type: 'work_constraint',
                priority: $row->severity === 'hard' ? 'critical' : 'warning',
                title: trans_message('ai_assistant.project_pulse.schedule.constraint_overdue_title'),
                text: trans_message('ai_assistant.project_pulse.schedule.constraint_overdue_text', [
                    'name' => (string) ($row->title ?? ('#' . $row->id)),
                ]),
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => 'work_constraint',
                    'id' => (int) $row->id,
                    'label' => trans_message('ai_assistant.project_pulse.schedule.constraint_label', [
                        'id' => (string) $row->id,
                    ]),
                    'route' => $this->lookaheadRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                ],
                source: $this->key(),
                category: 'schedule',
                status: $row->status,
                nextAction: trans_message('ai_assistant.project_pulse.schedule.constraint_next_action'),
                primaryAction: [
                    'label' => trans_message('ai_assistant.project_pulse.schedule.constraint_primary_action'),
                    'route' => $this->lookaheadRoute($row->project_id !== null ? (int) $row->project_id : null, (int) $row->schedule_id),
                    'permission' => 'schedule.view',
                ],
                deadline: (string) $row->due_date,
                ageDays: $this->ageDays($context, $row->due_date),
                meta: [
                    'constraint_type' => $row->constraint_type,
                    'severity' => $row->severity,
                    'schedule_task_id' => $row->schedule_task_id !== null ? (int) $row->schedule_task_id : null,
                    'schedule_task_name' => $row->task_name,
                    'lookahead_plan_id' => $row->lookahead_plan_id !== null ? (int) $row->lookahead_plan_id : null,
                ],
            ))->values();
    }

    private function scheduleRoute(?int $projectId, int $scheduleId): string
    {
        if ($projectId !== null) {
            return '/projects/' . $projectId . '/schedules/' . $scheduleId;
        }

        return '/schedules';
    }

    private function lookaheadRoute(?int $projectId, int $scheduleId): string
    {
        if ($projectId !== null) {
            return '/projects/' . $projectId . '/schedules/' . $scheduleId . '/lookahead';
        }

        return '/schedules';
    }
}
