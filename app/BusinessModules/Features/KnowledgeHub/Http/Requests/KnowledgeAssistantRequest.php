<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class KnowledgeAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'context_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
