<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;

class MaterialMovementsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'nullable|integer|exists:organization_warehouses,id',
            'material_id' => 'nullable|integer|exists:materials,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'movement_type' => 'nullable|string|in:receipt,issue,transfer,write_off,adjustment,return',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'user_id' => 'nullable|integer|exists:users,id',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Указанный склад не найден',
            'material_id.exists' => 'Указанный материал не найден',
            'project_id.exists' => 'Указанный проект не найден',
            'user_id.exists' => 'Указанный пользователь не найден',
            'date_from.required' => 'Необходимо указать дату начала периода',
            'date_to.required' => 'Необходимо указать дату окончания периода',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'movement_type.in' => 'Недопустимый тип операции',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

