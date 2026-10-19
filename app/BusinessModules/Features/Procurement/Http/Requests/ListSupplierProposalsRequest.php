<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSupplierProposalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'supplier_id' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('suppliers', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
