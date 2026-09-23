<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ComposeExecutiveActRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['hidden_work_act', 'axis_layout_act', 'geodetic_base_acceptance_act', 'responsible_structure_act', 'engineering_network_section_act'])],
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'work_type_id' => ['nullable', 'integer', 'min:1'],
            'completed_work_id' => ['nullable', 'integer', 'min:1'],
            'journal_entry_id' => ['nullable', 'integer', 'min:1'],
            'profile_data' => ['required', 'array'],
            'relations' => ['sometimes', 'array', 'max:100'],
            'relations.*.relation_type' => ['required', 'string', 'max:80'],
            'relations.*.target_type' => ['required', 'string', 'max:80'],
            'relations.*.target_id' => ['required', 'integer', 'min:1'],
            'signatories' => ['sometimes', 'array', 'max:20'],
            'signatories.*.role' => ['required', 'string', 'max:80'],
            'signatories.*.name' => ['required', 'string', 'max:255'],
            'signatories.*.organization' => ['nullable', 'string', 'max:255'],
            'signatories.*.authority_document' => ['nullable', 'string', 'max:500'],
        ];
    }
}
