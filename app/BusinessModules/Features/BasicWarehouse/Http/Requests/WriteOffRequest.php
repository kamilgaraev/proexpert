<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use function trans_message;

class WriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'warehouse_id' => [
                'required',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material_id' => [
                'required',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'cell_id' => 'nullable|integer',
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => ['nullable', 'integer'],
            'document_number' => 'nullable|string|max:100',
            'reason' => 'required|string|max:255',
            'operation_category' => [
                'required',
                Rule::in([
                    WarehouseMovement::CATEGORY_LOSS,
                    WarehouseMovement::CATEGORY_DAMAGE,
                    WarehouseMovement::CATEGORY_DISPOSAL,
                    WarehouseMovement::CATEGORY_INVENTORY_ADJUSTMENT,
                ]),
            ],
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'operation_category.required' => trans_message('basic_warehouse.validation.operation_category_required'),
            'operation_category.in' => trans_message('basic_warehouse.validation.production_usage_only_from_journal'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('project_id') || $this->input('project_id') === null) {
                    return;
                }

                $organizationId = $this->user()?->current_organization_id;
                $projectId = (int) $this->input('project_id');
                $projectIsAvailable = $organizationId !== null
                    && Project::query()
                        ->activeAccessibleByOrganization((int) $organizationId)
                        ->whereKey($projectId)
                        ->exists();

                if (! $projectIsAvailable) {
                    $validator->errors()->add(
                        'project_id',
                        trans_message('warehouse_basic.validation.project_invalid')
                    );
                }
            },
        ];
    }
}
