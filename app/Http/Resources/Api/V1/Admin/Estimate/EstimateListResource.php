<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'version' => $this->version,
            'estimate_date' => $this->estimate_date?->format('Y-m-d'),
            'total_amount' => (float) $this->total_amount,
            'total_amount_with_vat' => (float) $this->total_amount_with_vat,
            'project' => $this->whenLoaded('project', function() {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ];
            }),
            'contract' => $this->whenLoaded('contract', function() {
                return [
                    'id' => $this->contract->id,
                    'number' => $this->contract->number,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

