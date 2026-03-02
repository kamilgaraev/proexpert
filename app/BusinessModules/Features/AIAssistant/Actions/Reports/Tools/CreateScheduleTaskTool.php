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
        if (!$user) {
            return ['status' => 'error', 'message' => 'Пользователь не аутентифицирован'];
        }

        $projectId = $arguments['project_id'];
        $scheduleId = $arguments['schedule_id'];

        $schedule = ProjectSchedule::where('id', $scheduleId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$schedule) {
            return [
                'status' => 'error',
                'message' => "График с ID {$scheduleId} для проекта {$projectId} не найден."
            ];
        }

        $data = [
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => $arguments['name'],
            'planned_start_date' => $arguments['planned_start_date'],
            'planned_end_date' => $arguments['planned_end_date'],
            'parent_task_id' => $arguments['parent_task_id'] ?? null,
            'quantity' => $arguments['quantity'] ?? 0,
            'measurement_unit_id' => $arguments['measurement_unit_id'] ?? null,
            'task_type' => 'task',
            'status' => 'not_started',
            'priority' => 'normal',
        ];

        try {
            return DB::transaction(function () use ($data, $schedule) {
                // Вычисляем sort_order
                $data['sort_order'] = $this->taskService->getNextSortOrder(
                    $schedule->id,
                    $data['parent_task_id']
                );

                $task = ScheduleTask::create($data);

                return [
                    'status' => 'success',
                    'message' => "Задача '{$task->name}' успешно создана в графике.",
                    'task_id' => $task->id
                ];
            });
        } catch (\Exception $e) {
            Log::error('AI Tool Error (CreateScheduleTaskTool): ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Ошибка при создании задачи: ' . $e->getMessage()
            ];
        }
    }
}
