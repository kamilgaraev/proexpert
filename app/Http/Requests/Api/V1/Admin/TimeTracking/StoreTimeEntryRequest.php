<?php

namespace App\Http\Requests\Api\V1\Admin\TimeTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreTimeEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->hasPermissionTo('time_tracking.create');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')
            ],
            'worker_type' => [
                'nullable',
                'string',
                Rule::in(['user', 'virtual', 'brigade'])
            ],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                function ($attribute, $value, $fail) {
                    $workerType = $this->input('worker_type', 'user');
                    if ($workerType === 'user' && !$value) {
                        $fail('Для зарегистрированного работника необходимо указать ID пользователя');
                    }
                }
            ],
            'worker_name' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $workerType = $this->input('worker_type', 'user');
                    if (in_array($workerType, ['virtual', 'brigade']) && !$value) {
                        $fail('Для виртуального работника или бригады необходимо указать имя');
                    }
                }
            ],
            'worker_count' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000'
            ],
            'project_id' => [
                'required',
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
                'required',
                'date',
                'before_or_equal:today'
            ],
            'start_time' => [
                'nullable',
                'date_format:H:i'
            ],
            'end_time' => [
                'nullable',
                'date_format:H:i',
                'after:start_time'
            ],
            'hours_worked' => [
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
                'nullable',
                'numeric',
                'min:0'
            ],
            'title' => [
                'required',
                'string',
                'max:255'
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000'
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected'])
            ],
            'is_billable' => [
                'nullable',
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

        if (!$this->input('start_time') && !$this->input('end_time') && !$this->input('hours_worked')) {
            $rules['hours_worked'][] = 'required';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'organization_id.required' => 'Организация обязательна для заполнения',
            'organization_id.exists' => 'Выбранная организация не существует',
            'worker_type.in' => 'Тип работника должен быть: user, virtual или brigade',
            'user_id.required' => 'Пользователь обязателен для заполнения',
            'user_id.exists' => 'Выбранный пользователь не существует',
            'worker_name.required' => 'Имя работника обязательно для заполнения',
            'worker_name.max' => 'Имя работника не может превышать 255 символов',
            'worker_count.integer' => 'Количество работников должно быть целым числом',
            'worker_count.min' => 'Количество работников должно быть не менее 1',
            'worker_count.max' => 'Количество работников не может превышать 1000',
            'project_id.required' => 'Проект обязателен для заполнения',
            'project_id.exists' => 'Выбранный проект не существует',
            'work_type_id.exists' => 'Выбранный тип работы не существует',
            'task_id.exists' => 'Выбранная задача не существует',
            'work_date.required' => 'Дата работы обязательна для заполнения',
            'work_date.date' => 'Дата работы должна быть корректной датой',
            'work_date.before_or_equal' => 'Дата работы не может быть в будущем',
            'start_time.required' => 'Время начала обязательно для заполнения',
            'start_time.date_format' => 'Время начала должно быть в формате ЧЧ:ММ:СС или ЧЧ:ММ',
            'end_time.date_format' => 'Время окончания должно быть в формате ЧЧ:ММ:СС или ЧЧ:ММ',
            'end_time.after' => 'Время окончания должно быть позже времени начала',
            'hours_worked.required' => 'Укажите отработанные часы, время начала/окончания или объем работ',
            'hours_worked.numeric' => 'Отработанные часы должны быть числом',
            'hours_worked.min' => 'Отработанные часы должны быть больше 0',
            'hours_worked.max' => 'Отработанные часы не могут превышать 24',
            'break_time.numeric' => 'Время перерыва должно быть числом',
            'break_time.min' => 'Время перерыва не может быть отрицательным',
            'break_time.max' => 'Время перерыва не может превышать 24 часа',
            'volume_completed.numeric' => 'Объем работ должен быть числом',
            'volume_completed.min' => 'Объем работ не может быть отрицательным',
            'title.required' => 'Название обязательно для заполнения',
            'title.max' => 'Название не может превышать 255 символов',
            'description.max' => 'Описание не может превышать 1000 символов',
            'status.in' => 'Статус должен быть одним из: черновик, отправлено, утверждено, отклонено',
            'hourly_rate.numeric' => 'Почасовая ставка должна быть числом',
            'hourly_rate.min' => 'Почасовая ставка не может быть отрицательной',
            'location.max' => 'Местоположение не может превышать 255 символов',
            'notes.max' => 'Заметки не могут превышать 1000 символов'
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('worker_type')) {
            if ($this->has('user_id') && $this->input('user_id')) {
                $this->merge(['worker_type' => 'user']);
            } elseif ($this->has('worker_count') && $this->input('worker_count') > 1) {
                $this->merge(['worker_type' => 'brigade']);
            } elseif ($this->has('worker_name')) {
                $this->merge(['worker_type' => 'virtual']);
            } else {
                $this->merge(['worker_type' => 'user']);
            }
        }

        if (!$this->has('status')) {
            $this->merge(['status' => 'draft']);
        }

        if (!$this->has('is_billable')) {
            $this->merge(['is_billable' => true]);
        }
        
        if (!$this->has('organization_id') && auth()->user()) {
            $this->merge(['organization_id' => auth()->user()->current_organization_id]);
        }
    }

}