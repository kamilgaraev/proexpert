<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'contract_id' => $this->contract_id,
            'number' => $this->number,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'version' => $this->version,
            'parent_estimate_id' => $this->parent_estimate_id,
            'estimate_date' => $this->estimate_date?->format('Y-m-d'),
            'base_price_date' => $this->base_price_date?->format('Y-m-d'),
            'total_direct_costs' => (float) $this->total_direct_costs,
            'total_overhead_costs' => (float) $this->total_overhead_costs,
            'total_estimated_profit' => (float) $this->total_estimated_profit,
            'total_amount' => (float) $this->total_amount,
            'total_amount_with_vat' => (float) $this->total_amount_with_vat,
            'vat_rate' => (float) $this->vat_rate,
            'overhead_rate' => (float) $this->overhead_rate,
            'profit_rate' => (float) $this->profit_rate,
            'calculation_method' => $this->calculation_method,
            'approved_at' => $this->approved_at?->toISOString(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'project' => $this->whenLoaded('project'),
            'contract' => $this->whenLoaded('contract'),
            'approved_by' => $this->whenLoaded('approvedBy'),
            'sections' => EstimateSectionResource::collection($this->whenLoaded('sections')),
            'items' => EstimateItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

