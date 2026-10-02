<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantActionExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'array:id,preview_token,confirmed'],
            'action.id' => ['required', 'uuid'],
            'action.preview_token' => ['required', 'string', 'size:64'],
            'action.confirmed' => ['required', 'accepted'],
        ];
    }
}
