<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Public;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAnalyticsConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'analytics' => ['required', 'boolean'],
            'visitor_id' => ['required', 'uuid'],
            'event_id' => ['required', 'uuid'],
            'receipt_id' => ['nullable', 'uuid'],
            'legal_documents' => ['required_if:analytics,true', 'array:cookies'],
            'legal_documents.cookies' => ['required_if:analytics,true', new \App\Rules\CurrentLegalDocument('cookies')],
        ];
    }
}
