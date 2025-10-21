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
            'position_number' => $this->position_number,
            'name' => $this->name,
            'description' => $this->description,
            'work_type_id' => $this->work_type_id,
            'measurement_unit_id' => $this->measurement_unit_id,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'direct_costs' => (float) $this->direct_costs,
            'overhead_amount' => (float) $this->overhead_amount,
            'profit_amount' => (float) $this->profit_amount,
            'total_amount' => (float) $this->total_amount,
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

