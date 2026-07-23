<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitLegalArchiveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'document_version_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'step_overrides' => ['sometimes', 'array', 'max:100'],
            'additional_steps' => ['sometimes', 'array', 'max:100'],
        ];
    }
}
