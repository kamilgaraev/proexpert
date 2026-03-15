<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'zone_id' => 'nullable|integer|exists:warehouse_zones,id',
            'cell_id' => 'nullable|integer|exists:warehouse_storage_cells,id',
            'logistic_unit_id' => 'nullable|integer|exists:warehouse_logistic_units,id',
            'material_id' => 'nullable|integer|exists:materials,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'inventory_act_id' => 'nullable|integer|exists:inventory_acts,id',
            'movement_id' => 'nullable|integer|exists:warehouse_movements,id',
            'assigned_to_id' => 'nullable|integer|exists:users,id',
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
