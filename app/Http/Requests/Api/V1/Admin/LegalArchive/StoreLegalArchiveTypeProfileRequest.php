<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'schema' => ['sometimes', 'array', 'max:100'],
            'schema.*' => ['array:type,label,nullable,options,items,boolean_representations'],
            'schema.*.type' => ['required', Rule::in(['string', 'integer', 'number', 'boolean', 'date', 'array', 'enum'])],
            'schema.*.label' => ['required', 'string', 'max:255'],
            'required_fields' => ['sometimes', 'array', 'max:100'],
            'required_fields.*' => ['string', 'max:128'],
            'required_file_roles' => ['sometimes', 'array', 'max:50'],
            'required_file_roles.*' => ['string', 'max:64'],
            'requires_signature' => ['sometimes', 'boolean'],
            'allowed_signature_kinds' => ['sometimes', 'array', 'max:3'],
            'allowed_signature_kinds.*' => [Rule::in(['paper_original', 'external_electronic', 'provider_electronic'])],
            'required_signature_kinds' => ['sometimes', 'array', 'max:3'],
            'required_signature_kinds.*' => [Rule::in(['paper_original', 'external_electronic', 'provider_electronic'])],
            'allowed_signature_formats' => ['sometimes', 'array', 'max:3'],
            'allowed_signature_formats.*' => [Rule::in(['detached_cades', 'embedded_cades', 'xml_dsig'])],
            'workflow_template_id' => ['nullable', 'integer', 'min:1'],
            'retention_policy' => ['nullable', 'string', 'max:191'],
            'confidentiality_level' => ['nullable', 'string', 'max:64'],
        ];
    }
}
