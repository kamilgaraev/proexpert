<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractorMaterialTransferReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;
        return [
            'contractor_id' => ['nullable', 'integer', 'min:1', Rule::exists('contractors', 'id')->where('organization_id', $organizationId)],
            'material_id' => ['nullable', 'integer', 'min:1', Rule::exists('materials', 'id')->where('organization_id', $organizationId)],
            'warehouse_id' => ['nullable', 'integer', 'min:1', Rule::exists('organization_warehouses', 'id')->where('organization_id', $organizationId)],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
