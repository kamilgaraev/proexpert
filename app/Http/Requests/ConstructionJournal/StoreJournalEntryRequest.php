<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

class StoreJournalEntryRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return $this->entryRules(false);
    }

    protected function entryRules(bool $partial): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        return [
            'idempotency_key' => $partial ? 'prohibited' : 'required|string|max:100',
            'submit_after_create' => $partial ? 'prohibited' : 'sometimes|boolean',
            'status' => 'prohibited',
            'schedule_task_id' => $prefix.'nullable|integer',
            'estimate_id' => $prefix.'nullable|integer',
            'entry_date' => $partial ? 'sometimes|date' : 'required|date',
            'entry_number' => $prefix.'nullable|integer|min:1',
            'work_description' => $partial ? 'sometimes|string' : 'required|string',
            'weather_conditions' => $prefix.'nullable|array',
            'weather_conditions.temperature' => 'nullable|numeric',
            'weather_conditions.precipitation' => 'nullable|string',
            'weather_conditions.wind_speed' => 'nullable|numeric',
            'problems_description' => $prefix.'nullable|string',
            'safety_notes' => $prefix.'nullable|string',
            'visitors_notes' => $prefix.'nullable|string',
            'quality_notes' => $prefix.'nullable|string',
            'work_volumes' => $prefix.'nullable|array',
            'work_volumes.*.id' => 'nullable|integer',
            'work_volumes.*.estimate_item_id' => 'nullable|integer',
            'work_volumes.*.work_type_id' => 'nullable|integer',
            'work_volumes.*.quantity' => 'required|numeric|min:0.001',
            'work_volumes.*.measurement_unit_id' => 'nullable|integer',
            'work_volumes.*.notes' => 'nullable|string',
            'work_volumes.*.auto_attach_contract_coverage' => 'prohibited',
            'workers' => $prefix.'nullable|array',
            'workers.*.estimate_item_id' => 'nullable|integer',
            'workers.*.specialty' => 'required|string',
            'workers.*.workers_count' => 'required|integer|min:1',
            'workers.*.hours_worked' => 'nullable|numeric|min:0',
            'equipment' => $prefix.'nullable|array',
            'equipment.*.estimate_item_id' => 'nullable|integer',
            'equipment.*.equipment_name' => 'required|string',
            'equipment.*.equipment_type' => 'nullable|string',
            'equipment.*.quantity' => 'nullable|integer|min:1',
            'equipment.*.hours_used' => 'nullable|numeric|min:0',
            'materials' => $prefix.'nullable|array',
            'materials.*.estimate_item_id' => 'nullable|integer',
            'materials.*.material_id' => 'nullable|integer',
            'materials.*.project_material_delivery_id' => 'nullable|integer',
            'materials.*.material_name' => 'required|string',
            'materials.*.quantity' => 'required|numeric|min:0',
            'materials.*.measurement_unit' => 'required|string',
            'materials.*.notes' => 'nullable|string',
        ];
    }
}
