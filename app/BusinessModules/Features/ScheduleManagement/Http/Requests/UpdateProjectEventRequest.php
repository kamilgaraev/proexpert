<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => 'sometimes|string|in:inspection,delivery,meeting,maintenance,weather,other,site_request',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string', 'location' => 'nullable|string|max:255',
            'event_date' => 'sometimes|required|date',
            'event_time' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'end_time' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'duration_minutes' => 'nullable|integer|min:1', 'is_all_day' => 'sometimes|boolean',
            'end_date' => 'nullable|date', 'is_blocking' => 'sometimes|boolean',
            'priority' => 'sometimes|string|in:low,normal,high,critical,medium',
            'status' => 'sometimes|string|in:scheduled,in_progress,completed,cancelled',
            'schedule_id' => 'nullable|integer', 'related_task_id' => 'nullable|integer',
            'participants' => 'nullable|array', 'participants.*' => 'integer',
            'responsible_users' => 'nullable|array', 'responsible_users.*' => 'integer',
            'organizations' => 'nullable|array',
            'reminder_before_hours' => 'nullable|integer|min:1|max:720',
            'is_recurring' => 'sometimes|boolean',
            'recurrence_pattern' => 'nullable|string|in:daily,weekly,monthly',
            'recurrence_config' => 'nullable|array', 'recurrence_until' => 'nullable|date',
            'recurrence_scope' => 'sometimes|required|in:single,future',
            'operation_id' => 'sometimes|required|uuid', 'expected_revision' => 'sometimes|required|integer|min:0',
            'notes' => 'nullable|string', 'color' => 'nullable|string|max:7', 'icon' => 'nullable|string|max:50',
        ];
    }
}
