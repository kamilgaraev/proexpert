<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('organization_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'material_id' => [
                'required',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId),
            ],
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
