<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateItemWorkResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estimate_item_id' => $this->estimate_item_id,
            'caption' => $this->caption,
            'sort_order' => $this->sort_order,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
