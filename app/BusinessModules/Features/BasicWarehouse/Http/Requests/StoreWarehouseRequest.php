<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('organization_warehouses', 'code')->where('organization_id', $organizationId)],
            'warehouse_type' => 'nullable|in:central,project,external',
            'project_id' => [
                'nullable', 'integer', 'required_if:warehouse_type,project', 'prohibited_unless:warehouse_type,project',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'working_hours' => 'nullable|string|max:255',
            'is_main' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'settings' => 'nullable|array',
            'storage_conditions' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required_if' => trans_message('warehouse_creation.project_required'),
            'project_id.exists' => trans_message('warehouse_creation.project_invalid'),
            'project_id.prohibited_unless' => trans_message('warehouse_creation.project_prohibited'),
        ];
    }
}
