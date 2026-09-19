<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewContractPartiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'contract_side_type' => ['required', Rule::enum(ContractSideTypeEnum::class)],
            'direction' => ['required', Rule::in(['income', 'expense'])],
            'superior_organization_id' => ['nullable', 'integer', 'min:1'],
            'contractor_id' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
