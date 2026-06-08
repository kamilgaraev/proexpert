<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BudgetingFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'required' => trans_message('budgeting.validation.required'),
            'string' => trans_message('budgeting.validation.string'),
            'integer' => trans_message('budgeting.validation.integer'),
            'boolean' => trans_message('budgeting.validation.boolean'),
            'date' => trans_message('budgeting.validation.date'),
            'numeric' => trans_message('budgeting.validation.numeric'),
            'in' => trans_message('budgeting.validation.invalid_value'),
            'max' => trans_message('budgeting.validation.max'),
            'file' => trans_message('budgeting.validation.file'),
            'mimes' => trans_message('budgeting.validation.file_format'),
            'array' => trans_message('budgeting.validation.array'),
        ];
    }
}
