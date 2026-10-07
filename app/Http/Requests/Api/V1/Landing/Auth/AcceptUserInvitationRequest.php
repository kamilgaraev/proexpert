<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class AcceptUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'account_rules_accepted' => ['required', 'accepted'],
            'legal_documents' => ['required', 'array:accountRules,privacy'],
            'legal_documents.accountRules' => ['required', new \App\Rules\CurrentLegalDocument('accountRules')],
            'legal_documents.privacy' => ['required', new \App\Rules\CurrentLegalDocument('privacy')],
        ];
    }
}
