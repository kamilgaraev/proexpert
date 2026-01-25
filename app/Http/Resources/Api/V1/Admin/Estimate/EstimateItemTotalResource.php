<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateItemTotalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estimate_item_id' => $this->estimate_item_id,
            'data_type' => $this->data_type,
            'caption' => $this->caption,
            'quantity_for_one' => $this->quantity_for_one ? (float) $this->quantity_for_one : null,
            'quantity_total' => $this->quantity_total ? (float) $this->quantity_total : null,
            'for_one_curr' => $this->for_one_curr ? (float) $this->for_one_curr : null,
            'total_curr' => $this->total_curr ? (float) $this->total_curr : null,
            'total_base' => $this->total_base ? (float) $this->total_base : null,
            'sort_order' => $this->sort_order,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
