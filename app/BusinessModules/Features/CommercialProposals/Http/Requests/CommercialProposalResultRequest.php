<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommercialProposalResultRequest extends FormRequest
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
            'result' => ['required', 'string', Rule::in(['accepted', 'rejected', 'expired', 'cancelled'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'decided_at' => ['nullable', 'date'],
        ];
    }
}
