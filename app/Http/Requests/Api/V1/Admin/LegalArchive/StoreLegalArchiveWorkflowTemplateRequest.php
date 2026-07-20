<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLegalArchiveWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_.-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'steps' => ['required', 'array', 'min:1', 'max:100'],
            'steps.*.key' => ['required', 'string', 'max:128'],
            'steps.*.label' => ['required', 'string', 'max:255'],
            'steps.*.sequence' => ['required', 'integer', 'min:1'],
            'steps.*.actor_type' => ['required', 'string', 'max:64'],
            'steps.*.actor_reference' => ['required', 'string', 'max:191'],
            'steps.*.required' => ['sometimes', 'boolean'],
            'steps.*.due_in_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.settings' => ['sometimes', 'array'],
        ];
    }
}
