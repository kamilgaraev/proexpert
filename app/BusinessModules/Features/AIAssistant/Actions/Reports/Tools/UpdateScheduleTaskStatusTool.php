<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\ScheduleTask;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UpdateScheduleTaskStatusTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'update_task_status';
    }

    public function getDescription(): string
    {
        return 'Обновляет прогресс выполнения задачи в графике работ. Позволяет указать процент выполнения (0-100) или фактический объем выполненных работ.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'ID задачи из графика'
                ],
                'progress_percent' => [
                    'type' => 'number',
                    'description' => 'Процент выполнения (от 0 до 100). Необязательно, если указан выполненный объем.'
                ],
                'completed_quantity' => [
                    'type' => 'number',
                    'description' => 'Фактически выполненный объем работ (в единицах измерения задачи). Необязательно, если указан процент.'
                ]
            ],
            'required' => ['task_id']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $taskId = $arguments['task_id'];
        $task = ScheduleTask::where('id', $taskId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$task) {
            return [
                'status' => 'error',
                'message' => "Задача с ID {$taskId} не найдена в вашей организации."
            ];
        }

        $updateData = [];
        if (isset($arguments['progress_percent'])) {
            $updateData['progress_percent'] = $arguments['progress_percent'];
        }
        if (isset($arguments['completed_quantity'])) {
            $updateData['completed_quantity'] = $arguments['completed_quantity'];
        }

        if (empty($updateData)) {
            return [
                'status' => 'error',
                'message' => 'Не указаны данные для обновления (процент или объем).'
            ];
        }

        try {
            $task->update($updateData);

            // Если обновили объем, пересчитываем прогресс (если логика модели это поддерживает)
            if (isset($updateData['completed_quantity']) && method_exists($task, 'recalculateProgressFromQuantity')) {
                $task->recalculateProgressFromQuantity();
                $task->refresh();
            }

            // Пересчитываем прогресс графика (если логика модели это поддерживает через релешн)
            if ($task->schedule) {
                $task->schedule->recalculateProgress();
            }

            return [
                'status' => 'success',
                'message' => "Статус задачи '{$task->name}' успешно обновлен. Текущий прогресс: {$task->progress_percent}%",
                'task_id' => $task->id,
                'progress_percent' => $task->progress_percent
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (UpdateScheduleTaskStatusTool): ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Ошибка при обновлении задачи: ' . $e->getMessage()
            ];
        }
    }
}
