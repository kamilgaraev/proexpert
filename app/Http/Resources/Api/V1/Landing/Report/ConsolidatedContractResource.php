<?php

namespace App\Http\Resources\Api\V1\Landing\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsolidatedContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => [
                'id' => $this->organization_id,
                'name' => $this->organization->name ?? null,
            ],
            'number' => $this->number,
            'date' => $this->date?->format('Y-m-d'),
            'total_amount' => (float) ($this->total_amount ?? 0),
            'gp_percentage' => (float) ($this->gp_percentage ?? 0),
            'status' => is_object($this->status) ? $this->status->value : $this->status,
        ];
    }
} 