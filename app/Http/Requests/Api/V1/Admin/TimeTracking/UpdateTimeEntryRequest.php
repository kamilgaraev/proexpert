<?php

namespace App\Http\Requests\Api\V1\Admin\TimeTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateTimeEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('time_tracking.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id')
            ],
            'project_id' => [
                'sometimes',
                'integer',
                Rule::exists('projects', 'id')
            ],
            'work_type_id' => [
                'nullable',
                'integer',
                Rule::exists('work_types', 'id')
            ],
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('schedule_tasks', 'id')
            ],
            'work_date' => [
                'sometimes',
                'date',
                'before_or_equal:today'
            ],
            'start_time' => [
                'sometimes',
                'date_format:H:i:s'
            ],
            'end_time' => [
                'nullable',
                'date_format:H:i:s',
                'after:start_time'
            ],
            'break_time' => [
                'nullable',
                'numeric',
                'min:0',
                'max:24'
            ],
            'title' => [
                'sometimes',
                'string',
                'max:255'
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000'
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected'])
            ],
            'is_billable' => [
                'sometimes',
                'boolean'
            ],
            'hourly_rate' => [
                'nullable',
                'numeric',
                'min:0'
            ],
            'location' => [
                'nullable',
                'string',
                'max:255'
            ],
            'custom_fields' => [
                'nullable',
                'array'
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ]
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'Выбранный пользователь не существует',
            'project_id.exists' => 'Выбранный проект не существует',
            'work_type_id.exists' => 'Выбранный тип работы не существует',
            'task_id.exists' => 'Выбранная задача не существует',
            'work_date.date' => 'Дата работы должна быть корректной датой',
            'work_date.before_or_equal' => 'Дата работы не может быть в будущем',
            'start_time.date_format' => 'Время начала должно быть в формате ЧЧ:ММ:СС',
            'end_time.date_format' => 'Время окончания должно быть в формате ЧЧ:ММ:СС',
            'end_time.after' => 'Время окончания должно быть позже времени начала',
            'break_time.numeric' => 'Время перерыва должно быть числом',
            'break_time.min' => 'Время перерыва не может быть отрицательным',
            'break_time.max' => 'Время перерыва не может превышать 24 часа',
            'title.max' => 'Название не может превышать 255 символов',
            'description.max' => 'Описание не может превышать 1000 символов',
            'status.in' => 'Статус должен быть одним из: черновик, отправлено, утверждено, отклонено',
            'hourly_rate.numeric' => 'Почасовая ставка должна быть числом',
            'hourly_rate.min' => 'Почасовая ставка не может быть отрицательной',
            'location.max' => 'Местоположение не может превышать 255 символов',
            'notes.max' => 'Заметки не могут превышать 1000 символов'
        ];
    }
}