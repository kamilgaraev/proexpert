<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:60'], 'title' => ['required', 'string', 'max:255'],
            'file_id' => ['required', 'integer'], 'retention_class' => ['required', 'string', 'max:80'], 'retain_until' => ['nullable', 'date_format:Y-m-d'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'case_closed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:document_date'],
            'closure_basis_reference' => ['nullable', 'required_with:case_closed_on', 'string', 'max:255'],
            'supersedes_id' => ['nullable', 'integer'],
        ];
    }
}
