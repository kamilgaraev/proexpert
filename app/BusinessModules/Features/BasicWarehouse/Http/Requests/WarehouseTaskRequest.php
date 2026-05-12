<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()?->current_organization_id;
        $warehouseId = (int) $this->route('warehouseId');

        $rules = [
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_zones', 'id')->where('warehouse_id', $warehouseId),
            ],
            'cell_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_storage_cells', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId),
            ],
            'logistic_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_logistic_units', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId),
            ],
            'material_id' => [
                'nullable',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'inventory_act_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_acts', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId),
            ],
            'movement_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_movements', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId),
            ],
            'assigned_to_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('current_organization_id', $organizationId),
            ],
            'title' => 'required|string|max:255',
            'task_type' => 'required|in:receipt,placement,transfer,picking,cycle_count,issue,return,relabel,inspection',
            'status' => 'sometimes|in:draft,queued,in_progress,blocked,completed,cancelled',
            'priority' => 'sometimes|in:low,normal,high,critical',
            'planned_quantity' => 'nullable|numeric|min:0',
            'completed_quantity' => 'nullable|numeric|min:0',
            'due_at' => 'nullable|date',
            'source_document_type' => 'nullable|string|max:60',
            'source_document_id' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array',
            'notes' => 'nullable|string',
        ];

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required', 'sometimes', $rule);
            }
        }

        return $rules;
    }
}
