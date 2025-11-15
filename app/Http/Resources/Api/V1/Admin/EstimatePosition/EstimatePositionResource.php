<?php

namespace App\Http\Resources\Api\V1\Admin\EstimatePosition;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimatePositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'item_type' => $this->item_type,
            'measurement_unit_id' => $this->measurement_unit_id,
            'work_type_id' => $this->work_type_id,
            'unit_price' => (float) $this->unit_price,
            'formatted_price' => $this->formatted_price,
            'direct_costs' => $this->direct_costs ? (float) $this->direct_costs : null,
            'overhead_percent' => $this->overhead_percent ? (float) $this->overhead_percent : null,
            'profit_percent' => $this->profit_percent ? (float) $this->profit_percent : null,
            'is_active' => $this->is_active,
            'usage_count' => $this->usage_count,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Relationships
            'category' => $this->whenLoaded('category', function () {
                return $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'full_path' => $this->category->getFullPath(),
                ] : null;
            }),
            'measurement_unit' => $this->whenLoaded('measurementUnit', function () {
                return $this->measurementUnit ? [
                    'id' => $this->measurementUnit->id,
                    'name' => $this->measurementUnit->name,
                    'symbol' => $this->measurementUnit->symbol ?? null,
                ] : null;
            }),
            'work_type' => $this->whenLoaded('workType', function () {
                return $this->workType ? [
                    'id' => $this->workType->id,
                    'name' => $this->workType->name,
                    'code' => $this->workType->code ?? null,
                ] : null;
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null;
            }),
        ];
    }
}

