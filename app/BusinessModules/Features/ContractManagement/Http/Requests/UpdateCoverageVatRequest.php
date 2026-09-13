<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Http\Requests;

use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCoverageVatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            && (int) $contract->organization_id === (int) $this->attributes->get('current_organization_id');
    }

    public function rules(): array
    {
        $contract = $this->route('contract');

        return [
            'estimate_id' => ['required', 'integer', Rule::exists('estimates', 'id')
                ->where('organization_id', $contract->organization_id)->where('project_id', $contract->project_id)],
            'include_vat' => ['required', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'revision' => ['nullable', 'integer', 'min:0'],
            'mutation_id' => ['nullable', 'uuid'],
        ];
    }
}
