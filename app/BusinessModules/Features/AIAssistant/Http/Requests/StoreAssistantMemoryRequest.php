<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssistantMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:8000'],
            'confirmed' => ['required', function ($attribute, $value, $fail): void {
                if ($value !== true) {
                    $fail(trans_message('validation.accepted', ['attribute' => $attribute]));
                }
            }],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'source_refs' => ['sometimes', 'array', 'max:100'],
            'source_refs.*' => ['array:type,entity_type,id,entity_id,organization_id,fetched_at,source_id,checksum'],
            'source_refs.*.type' => ['required_without:source_refs.*.entity_type', 'string', 'max:80'],
            'source_refs.*.entity_type' => ['required_without:source_refs.*.type', 'string', 'max:80'],
            'source_refs.*.id' => ['required_without:source_refs.*.entity_id'],
            'source_refs.*.entity_id' => ['required_without:source_refs.*.id'],
            'source_refs.*.organization_id' => ['nullable', 'integer', 'min:1'],
            'source_refs.*.fetched_at' => ['sometimes', 'date'],
            'source_refs.*.source_id' => ['sometimes', 'integer', 'min:1'],
            'source_refs.*.checksum' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
