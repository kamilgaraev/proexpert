<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:5120'], 'conversation_id' => ['nullable', 'integer', 'min:1']];
    }
}
