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
            'item_type' => $this->item_type,
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
            'overhead_amount' => (float) $this->overhead_amount,
            'profit_amount' => (float) $this->profit_amount,
            'total_amount' => (float) $this->total_amount,
            'current_total_amount' => $this->current_total_amount ? (float) $this->current_total_amount : null,
            'justification' => $this->justification,
            'is_manual' => $this->is_manual,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'work_type' => $this->whenLoaded('workType'),
            'measurement_unit' => $this->whenLoaded('measurementUnit'),
            'resources' => EstimateItemResourceResource::collection($this->whenLoaded('resources')),
        ];
    }
}

