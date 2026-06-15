<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommercialProposalExportRequest extends FormRequest
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
            'version_id' => ['nullable', 'uuid'],
            'format' => ['nullable', 'string', Rule::in(['pdf', 'html'])],
            'options' => ['nullable', 'array'],
        ];
    }
}
