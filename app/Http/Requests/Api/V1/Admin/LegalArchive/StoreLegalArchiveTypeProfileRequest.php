<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLegalArchiveTypeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_.-]+$/'],
            'base_code' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'schema' => ['sometimes', 'array'],
            'required_fields' => ['sometimes', 'array'],
            'required_file_roles' => ['sometimes', 'array'],
            'requires_signature' => ['sometimes', 'boolean'],
            'allowed_signature_kinds' => ['sometimes', 'array'],
            'required_signature_kinds' => ['sometimes', 'array'],
            'allowed_signature_formats' => ['sometimes', 'array'],
            'workflow_template_id' => ['nullable', 'integer', 'min:1'],
            'retention_policy' => ['nullable', 'string', 'max:191'],
            'confidentiality_level' => ['nullable', 'string', 'max:64'],
        ];
    }
}
