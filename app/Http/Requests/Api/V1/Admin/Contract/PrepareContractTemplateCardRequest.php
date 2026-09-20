<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrepareContractTemplateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'resolve_only' => ['sometimes', 'boolean'],
            'project_id' => ['required', 'integer', 'min:1'],
            'contract_side_type' => ['required', Rule::in(['general_contract', 'contract', 'subcontract'])],
            'direction' => ['required', Rule::in(['income', 'expense'])],
            'superior_organization_id' => ['nullable', 'integer', 'min:1'],
            'contractor_id' => ['nullable', 'integer', 'min:1'],
            'number' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'template' => ['required', 'array:template_id,template_version,values'],
            'template.template_id' => ['required', 'uuid'],
            'template.template_version' => ['required', 'integer', 'min:1'],
            'template.values' => ['present', 'array', 'max:500'],
        ];
    }
}
