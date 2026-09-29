<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAssistantConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255']];
    }
}
