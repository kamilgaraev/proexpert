<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Schedule\PriorityEnum;
use App\Models\ScheduleTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskDependencyRequest extends FormRequest
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
            'predecessor_task_id' => ['nullable', 'integer', 'exists:schedule_tasks,id'],
            'successor_task_id' => ['nullable', 'integer', 'exists:schedule_tasks,id', 'different:predecessor_task_id'],
            'dependency_type' => ['nullable', 'string', Rule::in(['FS', 'SS', 'FF', 'SF'])],
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
            $predecessorTaskId = $this->has('predecessor_task_id') ? $this->integer('predecessor_task_id') : null;
            $successorTaskId = $this->has('successor_task_id') ? $this->integer('successor_task_id') : null;

            if ($predecessorTaskId) {
                $predecessorTask = ScheduleTask::query()->find($predecessorTaskId);

                if ($predecessorTask && $predecessorTask->schedule_id !== $scheduleId) {
                    $validator->errors()->add('predecessor_task_id', 'Предшествующая задача не принадлежит этому графику');
                }
            }

            if ($successorTaskId) {
                $successorTask = ScheduleTask::query()->find($successorTaskId);

                if ($successorTask && $successorTask->schedule_id !== $scheduleId) {
                    $validator->errors()->add('successor_task_id', 'Последующая задача не принадлежит этому графику');
                }
            }
        });
    }

    public function prepareForValidation(): void
    {
        if ($this->has('priority') && !is_numeric($this->priority)) {
            $this->merge([
                'priority' => PriorityEnum::from($this->priority ?? 'normal')->weight(),
            ]);
        }
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
