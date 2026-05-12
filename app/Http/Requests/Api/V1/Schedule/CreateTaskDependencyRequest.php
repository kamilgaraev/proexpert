<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Schedule\PriorityEnum;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        $organizationId = $this->getOrganizationId();

        if (!$organizationId) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization',
        ]);
    }

    public function rules(): array
    {
        return [
            'predecessor_task_id' => ['required', 'integer', 'exists:schedule_tasks,id'],
            'successor_task_id' => ['required', 'integer', 'exists:schedule_tasks,id', 'different:predecessor_task_id'],
            'dependency_type' => ['required', 'string', Rule::in(['FS', 'SS', 'FF', 'SF'])],
            'lag_days' => ['nullable', 'integer'],
            'lag_hours' => ['nullable', 'numeric', 'min:-999', 'max:999'],
            'lag_type' => ['nullable', 'string', Rule::in(['days', 'hours', 'percent'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_hard_constraint' => ['nullable', 'boolean'],
            'priority' => ['nullable'],
            'constraint_reason' => ['nullable', 'string', 'max:500'],
            'advanced_settings' => ['nullable', 'array'],
            'advanced_settings.*' => ['string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $scheduleId = (int) $this->route('schedule');
            $predecessorTask = ScheduleTask::query()->find($this->integer('predecessor_task_id'));
            $successorTask = ScheduleTask::query()->find($this->integer('successor_task_id'));

            if ($predecessorTask && $predecessorTask->schedule_id !== $scheduleId) {
                $validator->errors()->add('predecessor_task_id', 'Предшествующая задача не принадлежит этому графику');
            }

            if ($successorTask && $successorTask->schedule_id !== $scheduleId) {
                $validator->errors()->add('successor_task_id', 'Последующая задача не принадлежит этому графику');
            }

            if ($predecessorTask && $successorTask && $this->wouldCreateCycle($predecessorTask->id, $successorTask->id)) {
                $validator->errors()->add('successor_task_id', 'Такая зависимость создаст цикл в графике');
            }

            if ($predecessorTask && $successorTask) {
                $exists = TaskDependency::query()
                    ->where('schedule_id', $scheduleId)
                    ->where('predecessor_task_id', $predecessorTask->id)
                    ->where('successor_task_id', $successorTask->id)
                    ->where('is_active', true)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('successor_task_id', 'Зависимость между этими задачами уже существует');
                }
            }
        });
    }

    protected function wouldCreateCycle(int $predecessorId, int $successorId): bool
    {
        return TaskDependency::query()
            ->where('predecessor_task_id', $successorId)
            ->where('successor_task_id', $predecessorId)
            ->where('is_active', true)
            ->exists();
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'lag_days' => $this->lag_days ?? 0,
            'lag_hours' => $this->lag_hours ?? 0.0,
            'lag_type' => $this->lag_type ?? 'days',
            'is_hard_constraint' => $this->is_hard_constraint ?? false,
            'priority' => is_numeric($this->priority)
                ? (int) $this->priority
                : PriorityEnum::from($this->priority ?? 'normal')->weight(),
        ]);
    }

    protected function getOrganizationId(): ?int
    {
        $user = $this->user();
        $organizationId = $this->attributes->get('current_organization_id')
            ?? $user?->current_organization_id
            ?? $user?->organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
