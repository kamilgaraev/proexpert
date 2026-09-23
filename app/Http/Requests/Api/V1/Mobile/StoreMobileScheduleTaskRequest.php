<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Http\Responses\MobileResponse;
use Illuminate\Validation\Rule;

final class StoreMobileScheduleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()->current_organization_id;
        $scheduleId = (int) $this->route('schedule_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'planned_duration_days' => ['nullable', 'integer', 'min:1'],
            'task_type' => ['nullable', 'string', Rule::in(['task', 'milestone', 'summary', 'container'])],
            'status' => ['nullable', 'string', Rule::in(['not_started', 'in_progress', 'completed', 'cancelled', 'on_hold'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'critical'])],
            'parent_task_id' => [
                'nullable', 'integer',
                Rule::exists('schedule_tasks', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('schedule_id', $scheduleId)
                    ->whereNull('deleted_at'),
            ],
            'assigned_user_id' => [
                'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('schedule_management.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
