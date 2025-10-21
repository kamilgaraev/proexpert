<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateItemResourceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estimate_item_id' => $this->estimate_item_id,
            'resource_type' => $this->resource_type,
            'material_id' => $this->material_id,
            'name' => $this->name,
            'description' => $this->description,
            'measurement_unit_id' => $this->measurement_unit_id,
            'quantity_per_unit' => (float) $this->quantity_per_unit,
            'total_quantity' => (float) $this->total_quantity,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'material' => $this->whenLoaded('material'),
            'measurement_unit' => $this->whenLoaded('measurementUnit'),
        ];
    }
}

