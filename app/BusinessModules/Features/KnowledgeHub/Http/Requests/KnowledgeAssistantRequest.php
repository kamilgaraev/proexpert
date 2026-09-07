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
            'question' => ['required', 'string', 'min:1', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*' => ['required', 'array:role,content'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
            'context_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
