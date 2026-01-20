<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'act_document_number' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'act_date' => [
                'sometimes',
                'required',
                'date',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'act_document_number' => 'номер акта',
            'act_date' => 'дата акта',
            'description' => 'описание',
        ];
    }

    public function messages(): array
    {
        return [
            'act_document_number.required' => 'Необходимо указать номер акта',
            'act_document_number.max' => 'Номер акта не должен превышать 255 символов',
            'act_date.required' => 'Необходимо указать дату акта',
            'act_date.date' => 'Неверный формат даты',
        ];
    }
}
