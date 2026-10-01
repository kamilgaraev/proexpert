<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\ScheduleTask;
use App\Models\ProjectSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Services\Schedule\ScheduleTaskService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CreateScheduleTaskTool implements AIToolInterface
{
    protected ScheduleTaskService $taskService;

    public function __construct(ScheduleTaskService $taskService)
    {
        $this->taskService = $taskService;
    }

    public function getName(): string
    {
        return 'create_schedule_task';
    }

    public function getDescription(): string
    {
        return 'Создает новую задачу в графике работ проекта. Позволяет указать название, даты начала и окончания, объем работ и родительскую задачу.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта'
                ],
                'schedule_id' => [
                    'type' => 'integer',
                    'description' => 'ID графика (ProjectSchedule)'
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Название задачи'
                ],
                'planned_start_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Плановая дата начала (YYYY-MM-DD)'
                ],
                'planned_end_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Плановая дата окончания (YYYY-MM-DD)'
                ],
                'parent_task_id' => [
                    'type' => 'integer',
                    'description' => 'ID родительской задачи (необязательно)'
                ],
                'quantity' => [
                    'type' => 'number',
                    'description' => 'Объем работ (необязательно)'
                ],
                'measurement_unit_id' => [
                    'type' => 'integer',
                    'description' => 'ID единицы измерения (необязательно)'
                ]
            ],
            'required' => ['project_id', 'schedule_id', 'name', 'planned_start_date', 'planned_end_date']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)
                ->canExecuteTool($user, $this->getName(), $arguments)) {
            return ['status' => 'error', 'message' => trans_message('ai_assistant.tool_access_denied')];
        }

        try {
            return DB::transaction(function () use ($arguments, $user, $organization): array {
                $schedule = ProjectSchedule::query()->whereKey($arguments['schedule_id'] ?? null)
                    ->where('project_id', $arguments['project_id'] ?? null)
                    ->where('organization_id', $organization->id)->lockForUpdate()->first();
                if ($schedule === null) {
                    return ['status' => 'error', 'message' => trans_message('ai_assistant.tool_access_denied')];
                }
                $parentId = $arguments['parent_task_id'] ?? null;
                if ($parentId !== null && ! ScheduleTask::query()->whereKey($parentId)
                    ->where('organization_id', $organization->id)->where('schedule_id', $schedule->id)
                    ->whereIn('task_type', ['summary', 'container'])->lockForUpdate()->first()) {
                    return ['status' => 'error', 'message' => trans_message('ai_assistant.tool_access_denied')];
                }
                $task = ScheduleTask::create([
                    'schedule_id' => $schedule->id,
                    'organization_id' => $organization->id,
                    'created_by_user_id' => $user->id,
                    'name' => $arguments['name'],
                    'planned_start_date' => $arguments['planned_start_date'],
                    'planned_end_date' => $arguments['planned_end_date'],
                    'parent_task_id' => $parentId,
                    'quantity' => $arguments['quantity'] ?? 0,
                    'measurement_unit_id' => $arguments['measurement_unit_id'] ?? null,
                    'task_type' => 'task',
                    'status' => 'not_started',
                    'priority' => 'normal',
                    'sort_order' => $this->taskService->getNextSortOrder($schedule->id, $parentId),
                ]);

                return ['status' => 'success', 'message' => trans_message('ai_assistant.action_executed'), 'task_id' => $task->id];
            });
        } catch (\Throwable $exception) {
            Log::error('ai.assistant.schedule_task.failed', ['organization_id' => $organization->id, 'schedule_id' => $arguments['schedule_id'] ?? null, 'exception' => $exception::class]);
            return ['status' => 'error', 'message' => trans_message('ai_assistant.action_invalid')];
        }
    }
}