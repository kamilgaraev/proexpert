<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssistantChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'attachment_ids' => ['sometimes', 'array', 'max:2'],
            'attachment_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'request_id' => ['required', 'uuid'],
            'quote_id' => ['required', 'uuid'],
            'profile' => ['sometimes', Rule::in(['short', 'normal', 'detailed'])],
            'goal' => ['nullable', 'string', 'max:120'],
            'desired_mode' => ['nullable', 'string', 'max:120'],
            'allow_actions' => ['sometimes', 'boolean'],
            'async' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'array:source_module,source_route,entity_refs,period,filters,ui_state'],
            'context.source_module' => ['nullable', 'string', 'max:120'],
            'context.source_route' => ['nullable', 'string', 'max:255'],
            'context.entity_refs' => ['sometimes', 'array', 'max:20'],
            'context.entity_refs.*' => ['array:type,id,label'],
            'context.entity_refs.*.type' => ['required', 'string', 'max:80'],
            'context.entity_refs.*.id' => ['nullable'],
            'context.entity_refs.*.label' => ['nullable', 'string', 'max:255'],
            'context.period' => ['nullable'],
            'context.filters' => ['sometimes', 'array', 'max:30'],
            'context.ui_state' => ['sometimes', 'array', 'max:20'],
        ];
    }
}
