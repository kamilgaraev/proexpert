<?php

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateActReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'performance_act_id' => [
                'required',
                'integer',
                'exists:contract_performance_acts,id'
            ],
            'format' => [
                'required',
                'string',
                Rule::in(['pdf', 'excel'])
            ],
            'title' => [
                'nullable',
                'string',
                'max:255'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'performance_act_id.required' => 'ID акта выполненных работ обязателен',
            'performance_act_id.integer' => 'ID акта должен быть числом',
            'performance_act_id.exists' => 'Указанный акт не найден',
            'format.required' => 'Формат отчета обязателен',
            'format.in' => 'Формат отчета должен быть pdf или excel',
            'title.max' => 'Заголовок не должен превышать 255 символов'
        ];
    }
} 