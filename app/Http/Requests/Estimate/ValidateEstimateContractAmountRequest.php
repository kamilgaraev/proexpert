<?php

declare(strict_types=1);

namespace App\Http\Requests\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateEstimateContractAmountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $includeVat = $this->input('include_vat');

        if (! is_string($includeVat)) {
            return;
        }

        $normalized = match (strtolower(trim($includeVat))) {
            'true' => true,
            'false' => false,
            default => null,
        };

        if ($normalized !== null) {
            $this->merge(['include_vat' => $normalized]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'integer'],
            'include_vat' => ['nullable', 'boolean'],
        ];
    }
}
