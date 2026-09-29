<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantCreditQuoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        $rules = (new AssistantChatRequest)->rules();
        unset($rules['quote_id']);
        $rules['profile'] = ['required', 'in:short,normal,detailed'];

        return $rules;
    }
}
