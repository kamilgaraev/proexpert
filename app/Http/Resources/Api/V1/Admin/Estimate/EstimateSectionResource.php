<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateSectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'parent_section_id' => $this->parent_section_id,
            'section_number' => $this->section_number,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_summary' => $this->is_summary,
            'section_total_amount' => (float) $this->section_total_amount,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'children' => static::collection($this->whenLoaded('children')),
            'items' => EstimateItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

