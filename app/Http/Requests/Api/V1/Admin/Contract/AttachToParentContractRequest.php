<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\ParentContractValid;

class AttachToParentContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contractId = $this->route('contract');
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        return [
            'parent_contract_id' => [
                'required',
                'integer',
                'exists:contracts,id',
                new ParentContractValid(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_contract_id.required' => 'ID родительского контракта обязателен',
            'parent_contract_id.integer' => 'ID родительского контракта должен быть числом',
            'parent_contract_id.exists' => 'Родительский контракт не найден',
        ];
    }

    protected function prepareForValidation(): void
    {
        $contractId = $this->route('contract');
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        $this->merge([
            'id' => $contractId,
            'organization_id' => $organizationId,
        ]);
    }
}

