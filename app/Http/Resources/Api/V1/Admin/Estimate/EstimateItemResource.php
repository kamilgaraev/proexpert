<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'estimate_section_id' => $this->estimate_section_id,
            'parent_item_id' => $this->parent_work_id, // ID родительской позиции для вложенности
            'item_type' => $this->item_type?->value ?? $this->item_type,
            'item_type_label' => $this->item_type?->label() ?? null,
            'position_number' => $this->position_number,
            'name' => $this->name,
            'description' => $this->description,
            'work_type_id' => $this->work_type_id,
            'measurement_unit_id' => $this->measurement_unit_id,
            'quantity' => (float) $this->quantity,
            'quantity_coefficient' => $this->quantity_coefficient ? (float) $this->quantity_coefficient : null,
            'quantity_total' => $this->quantity_total ? (float) $this->quantity_total : null,
            'unit_price' => (float) $this->unit_price,
            'base_unit_price' => $this->base_unit_price ? (float) $this->base_unit_price : null,
            'price_index' => $this->price_index ? (float) $this->price_index : null,
            'current_unit_price' => $this->current_unit_price ? (float) $this->current_unit_price : null,
            'price_coefficient' => $this->price_coefficient ? (float) $this->price_coefficient : null,
            'direct_costs' => (float) $this->direct_costs,
            
            // Стоимость по компонентам
            'materials_cost' => $this->materials_cost ? (float) $this->materials_cost : null,
            'machinery_cost' => $this->machinery_cost ? (float) $this->machinery_cost : null,
            'labor_cost' => $this->labor_cost ? (float) $this->labor_cost : null,
            'equipment_cost' => $this->equipment_cost ? (float) $this->equipment_cost : null,
            
            'overhead_amount' => (float) $this->overhead_amount,
            'profit_amount' => (float) $this->profit_amount,
            'total_amount' => (float) $this->total_amount,
            'total_amount_with_vat' => $this->estimate ? round($this->total_amount * (1 + ($this->estimate->vat_rate ?? 0) / 100), 2) : (float) $this->total_amount,
            'current_total_amount' => $this->current_total_amount ? (float) $this->current_total_amount : null,
            
            // Трудозатраты
            'labor_hours' => $this->labor_hours ? (float) $this->labor_hours : null,
            'machinery_hours' => $this->machinery_hours ? (float) $this->machinery_hours : null,
            
            // Базовая стоимость (для базисно-индексного метода)
            'base_costs' => $this->base_materials_cost || $this->base_machinery_cost || $this->base_labor_cost ? [
                'materials' => (float) $this->base_materials_cost,
                'machinery' => (float) $this->base_machinery_cost,
                'labor' => (float) $this->base_labor_cost,
                'unit_price' => (float) $this->base_unit_price,
            ] : null,
            
            // Индексы (детализация)
            'indices' => $this->price_index || $this->materials_index ? [
                'general' => $this->price_index ? (float) $this->price_index : null,
                'materials' => $this->materials_index ? (float) $this->materials_index : null,
                'machinery' => $this->machinery_index ? (float) $this->machinery_index : null,
                'labor' => $this->labor_index ? (float) $this->labor_index : null,
            ] : null,
            
            // Нормативная база
            'normative_rate_id' => $this->normative_rate_id,
            'normative_rate_code' => $this->normative_rate_code,
            
            'justification' => $this->justification,
            'is_manual' => $this->is_manual,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'work_type' => $this->whenLoaded('workType'),
            'measurement_unit' => $this->whenLoaded('measurementUnit'),
            'resources' => EstimateItemResourceResource::collection($this->whenLoaded('resources')),
            'works' => EstimateItemWorkResource::collection($this->whenLoaded('works')),
            'totals' => EstimateItemTotalResource::collection($this->whenLoaded('totals')),
            'children' => EstimateItemResource::collection($this->whenLoaded('childItems')),
        ];
    }
}

