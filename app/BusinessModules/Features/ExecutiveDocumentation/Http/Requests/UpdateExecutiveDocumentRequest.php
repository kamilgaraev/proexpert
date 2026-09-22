<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateExecutiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'expected_version_id' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'section_name' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'inspection_date' => ['nullable', 'date_format:Y-m-d'],
            'participants' => ['nullable', 'array'],
            'profile_data' => ['nullable', 'array'],
            'signatories' => ['nullable', 'array'],
            'work_type_id' => ['nullable', 'integer', 'min:1'],
            'work_type_name' => ['nullable', 'string', 'max:255'],
            'completed_work_id' => ['nullable', 'integer', 'min:1'],
            'journal_entry_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
