<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommercialProposalApprovalDecisionRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
