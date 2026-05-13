<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use App\Models\EstimateSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('budget-estimates.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $section = $this->route('section');
        $estimateId = (int) ($this->route('estimateId') ?: ($section instanceof EstimateSection ? $section->estimate_id : 0));
        $sectionId = $section instanceof EstimateSection ? (int) $section->id : 0;

        return [
            'section_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'parent_section_id' => [
                'nullable',
                'integer',
                Rule::exists('estimate_sections', 'id')->where('estimate_id', $estimateId),
                Rule::notIn([$sectionId]),
            ],
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
            'name.max' => 'Название раздела не может быть длиннее 255 символов',
            'parent_section_id.exists' => 'Указанный родительский раздел не существует',
        ];
    }
}

