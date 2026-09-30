<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantCreditPurchaseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['pack_id' => ['required', 'in:ai-credits-1000,ai-credits-5000,ai-credits-10000'], 'request_id' => ['sometimes', 'uuid']];
    }
}
