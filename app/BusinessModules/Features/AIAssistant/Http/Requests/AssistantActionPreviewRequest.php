<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantActionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'array'],
            'action.tool_name' => ['required', 'string', 'max:120'],
            'action.arguments' => ['required', 'array'],
            'action.origin_request_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
