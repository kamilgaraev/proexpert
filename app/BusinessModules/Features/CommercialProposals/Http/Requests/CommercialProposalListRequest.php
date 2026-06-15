<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommercialProposalListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in([
                'draft',
                'internal_review',
                'approved',
                'sent',
                'customer_review',
                'accepted',
                'rejected',
                'expired',
                'cancelled',
            ])],
            'customer' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'crm_deal_id' => ['nullable', 'uuid'],
            'tender_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
