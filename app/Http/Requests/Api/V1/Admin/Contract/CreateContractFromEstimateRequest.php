<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Validation\Rule;

final class CreateContractFromEstimateRequest extends StoreContractRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'estimate_id' => [
                'required',
                'integer',
                Rule::exists('estimates', 'id')->where(
                    'organization_id',
                    $this->currentOrganizationId(),
                )->where('project_id', $this->routeProjectId()),
            ],
            'estimate_item_ids' => ['required', 'array', 'min:1'],
            'estimate_item_ids.*' => ['required', 'integer', 'distinct'],
            'include_vat' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
    }
}
