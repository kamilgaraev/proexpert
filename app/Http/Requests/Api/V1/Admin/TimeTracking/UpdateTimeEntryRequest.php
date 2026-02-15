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
        return \Illuminate\Support\Facades\Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'worker_type' => [
                'sometimes',
                'string',
                Rule::in(['user', 'virtual', 'brigade'])
            ],
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
            ],
            'worker_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255'
            ],
            'worker_count' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:1000'
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
                'nullable',
                'date_format:H:i'
            ],
            'end_time' => [
                'nullable',
                'date_format:H:i',
                'after:start_time'
            ],
            'hours_worked' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.01',
                'max:24'
            ],
            'break_time' => [
                'nullable',
                'numeric',
                'min:0',
                'max:24'
            ],
            'volume_completed' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0'
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
            'worker_type.in' => 'Тип работника должен быть: user, virtual или brigade',
            'user_id.exists' => 'Выбранный пользователь не существует',
            'worker_name.max' => 'Имя работника не может превышать 255 символов',
            'worker_count.integer' => 'Количество работников должно быть целым числом',
            'worker_count.min' => 'Количество работников должно быть не менее 1',
            'worker_count.max' => 'Количество работников не может превышать 1000',
            'project_id.exists' => 'Выбранный проект не существует',
            'work_type_id.exists' => 'Выбранный тип работы не существует',
            'task_id.exists' => 'Выбранная задача не существует',
            'work_date.date' => 'Дата работы должна быть корректной датой',
            'work_date.before_or_equal' => 'Дата работы не может быть в будущем',
            'start_time.date_format' => 'Время начала должно быть в формате ЧЧ:ММ:СС или ЧЧ:ММ',
            'end_time.date_format' => 'Время окончания должно быть в формате ЧЧ:ММ:СС или ЧЧ:ММ',
            'end_time.after' => 'Время окончания должно быть позже времени начала',
            'hours_worked.numeric' => 'Отработанные часы должны быть числом',
            'hours_worked.min' => 'Отработанные часы должны быть больше 0',
            'hours_worked.max' => 'Отработанные часы не могут превышать 24',
            'break_time.numeric' => 'Время перерыва должно быть числом',
            'break_time.min' => 'Время перерыва не может быть отрицательным',
            'break_time.max' => 'Время перерыва не может превышать 24 часа',
            'volume_completed.numeric' => 'Объем работ должен быть числом',
            'volume_completed.min' => 'Объем работ не может быть отрицательным',
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