<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReturnFromResponsibleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'custody_warehouse_id' => [
                'required',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
                    ->where('is_active', true),
            ],
            'material_id' => [
                'required',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
