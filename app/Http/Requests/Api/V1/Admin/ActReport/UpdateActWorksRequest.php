<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActWorksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'works' => [
                'required',
                'array',
                'min:1',
            ],
            'works.*.work_id' => [
                'required',
                'integer',
                'exists:completed_works,id',
            ],
            'works.*.included_quantity' => [
                'required',
                'numeric',
                'min:0',
            ],
            'works.*.included_amount' => [
                'required',
                'numeric',
                'min:0',
            ],
            'works.*.notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'works' => 'работы',
            'works.*.work_id' => 'ID работы',
            'works.*.included_quantity' => 'количество',
            'works.*.included_amount' => 'сумма',
            'works.*.notes' => 'примечание',
        ];
    }

    public function messages(): array
    {
        return [
            'works.required' => 'Необходимо указать работы',
            'works.array' => 'Работы должны быть массивом',
            'works.min' => 'Необходимо указать хотя бы одну работу',
            'works.*.work_id.required' => 'Необходимо указать ID работы',
            'works.*.work_id.exists' => 'Работа не существует',
            'works.*.included_quantity.required' => 'Необходимо указать количество',
            'works.*.included_quantity.numeric' => 'Количество должно быть числом',
            'works.*.included_amount.required' => 'Необходимо указать сумму',
            'works.*.included_amount.numeric' => 'Сумма должна быть числом',
        ];
    }
}
