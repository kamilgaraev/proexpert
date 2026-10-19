<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CommercialProposalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge((new CommercialProposalVersionRequest())->rules(), [
            'crm_deal_id' => ['nullable', 'uuid'],
        ]);
    }
}
