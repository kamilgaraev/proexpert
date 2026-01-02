<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('budget-estimates.create');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'section_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'parent_section_id' => 'nullable|integer|exists:estimate_sections,id',
            'sort_order' => 'nullable|integer|min:0',
            'is_summary' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название раздела обязательно',
            'name.max' => 'Название раздела не может быть длиннее 255 символов',
            'parent_section_id.exists' => 'Указанный родительский раздел не существует',
        ];
    }
}
