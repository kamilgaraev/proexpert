<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;

class TimeTrackingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'nullable|integer|exists:users,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'work_type_id' => 'nullable|integer|exists:work_types,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'status' => 'nullable|string|in:draft,submitted,approved,rejected',
            'is_billable' => 'nullable|in:0,1,true,false',
            'group_by' => 'nullable|string|in:user,project,date,work_type',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'Указанный пользователь не найден',
            'project_id.exists' => 'Указанный проект не найден',
            'work_type_id.exists' => 'Указанный тип работ не найден',
            'date_from.required' => 'Необходимо указать дату начала периода',
            'date_to.required' => 'Необходимо указать дату окончания периода',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'status.in' => 'Недопустимый статус записи',
            'group_by.in' => 'Недопустимый тип группировки',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

