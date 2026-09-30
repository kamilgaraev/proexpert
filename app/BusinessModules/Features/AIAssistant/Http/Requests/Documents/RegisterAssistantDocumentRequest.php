<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterAssistantDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void
    {
        if (is_int($this->input('parent_entity_id'))) $this->merge(['parent_entity_id' => (string) $this->input('parent_entity_id')]);
    }
    public function rules(): array { return ['parent_entity_type' => ['required', 'string', 'max:80'], 'parent_entity_id' => ['required', 'string', 'max:80'], 'storage_path' => ['required', 'string', 'max:1024'], 'filename' => ['required', 'string', 'max:255'], 'mime_type' => ['required', 'string', 'max:160'], 'project_id' => ['nullable', 'integer']]; }
}
