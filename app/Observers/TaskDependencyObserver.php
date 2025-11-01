<?php

namespace App\Observers;

use App\Models\TaskDependency;
use App\Exceptions\Schedule\ScheduleValidationException;
use App\Exceptions\Schedule\CircularDependencyException;
use Illuminate\Support\Facades\Log;

class TaskDependencyObserver
{
    /**
     * Валидация при создании зависимости
     */
    public function creating(TaskDependency $dependency): void
    {
        $this->validateTasksExist($dependency);
        $this->validateTasksBelongToSameSchedule($dependency);
        $this->validateNotSelfReferencing($dependency);
        $this->validateDates($dependency);
    }

    /**
     * Валидация при сохранении зависимости
     */
    public function saving(TaskDependency $dependency): void
    {
        // Проверка на циклические зависимости
        if ($dependency->createsCycle()) {
            throw new CircularDependencyException(
                'Создание этой зависимости приведет к циклической зависимости',
                [$dependency->predecessor_task_id, $dependency->successor_task_id]
            );
        }
    }

    /**
     * Проверка существования задач
     */
    protected function validateTasksExist(TaskDependency $dependency): void
    {
        $errors = [];

        if (!$dependency->predecessorTask) {
            $errors['predecessor_task_id'] = ['Предшествующая задача не найдена'];
        }

        if (!$dependency->successorTask) {
            $errors['successor_task_id'] = ['Последующая задача не найдена'];
        }

        if (!empty($errors)) {
            throw new ScheduleValidationException('Задачи для зависимости не найдены', $errors);
        }
    }

    /**
     * Проверка, что задачи принадлежат одному графику
     */
    protected function validateTasksBelongToSameSchedule(TaskDependency $dependency): void
    {
        $predecessor = $dependency->predecessorTask;
        $successor = $dependency->successorTask;

        if ($predecessor && $successor) {
            if ($predecessor->schedule_id !== $successor->schedule_id) {
                throw new ScheduleValidationException(
                    'Задачи должны принадлежать одному графику',
                    [
                        'predecessor_task_id' => ['Задача принадлежит другому графику'],
                        'successor_task_id' => ['Задача принадлежит другому графику'],
                    ]
                );
            }

            // Устанавливаем schedule_id если не установлен
            if (!$dependency->schedule_id) {
                $dependency->schedule_id = $predecessor->schedule_id;
            }

            // Устанавливаем organization_id если не установлен
            if (!$dependency->organization_id && $predecessor->organization_id) {
                $dependency->organization_id = $predecessor->organization_id;
            }
        }
    }

    /**
     * Проверка, что задача не ссылается сама на себя
     */
    protected function validateNotSelfReferencing(TaskDependency $dependency): void
    {
        if ($dependency->predecessor_task_id === $dependency->successor_task_id) {
            throw new ScheduleValidationException(
                'Задача не может зависеть от самой себя',
                [
                    'predecessor_task_id' => ['Задача не может быть предшественником самой себя'],
                    'successor_task_id' => ['Задача не может быть последующей для самой себя'],
                ]
            );
        }
    }

    /**
     * Валидация дат зависимостей
     */
    protected function validateDates(TaskDependency $dependency): void
    {
        $predecessor = $dependency->predecessorTask;
        $successor = $dependency->successorTask;

        if (!$predecessor || !$successor) {
            return;
        }

        // Проверяем логику дат в зависимости от типа зависимости
        $validationStatus = $dependency->validateDependency();
        
        if ($validationStatus !== 'valid') {
            $message = match($validationStatus) {
                'creates_cycle' => 'Создание этой зависимости приведет к циклу',
                'invalid_dates' => 'Даты задач не соответствуют типу зависимости',
                'resource_conflict' => 'Обнаружен конфликт ресурсов между задачами',
                default => 'Невалидная зависимость',
            };

            throw new ScheduleValidationException($message, [
                'dependency_type' => [$message],
            ]);
        }
    }
}

