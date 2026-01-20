<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class StoreActReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Авторизация на уровне middleware/политик
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'contract_id' => [
                'required',
                'integer',
                'exists:contracts,id',
            ],
            'act_document_number' => [
                'required',
                'string',
                'max:255',
            ],
            'act_date' => [
                'required',
                'date',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'work_ids' => [
                'nullable',
                'array',
            ],
            'work_ids.*' => [
                'integer',
                'exists:completed_works,id',
            ],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'contract_id' => 'ID контракта',
            'act_document_number' => 'номер акта',
            'act_date' => 'дата акта',
            'description' => 'описание',
            'work_ids' => 'работы',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'contract_id.required' => 'Необходимо указать контракт',
            'contract_id.exists' => 'Выбранный контракт не существует',
            'act_document_number.required' => 'Необходимо указать номер акта',
            'act_document_number.max' => 'Номер акта не должен превышать 255 символов',
            'act_date.required' => 'Необходимо указать дату акта',
            'act_date.date' => 'Неверный формат даты',
            'work_ids.array' => 'Работы должны быть массивом',
            'work_ids.*.exists' => 'Одна или несколько работ не существуют',
        ];
    }
}
