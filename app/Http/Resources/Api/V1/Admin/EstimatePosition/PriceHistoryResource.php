<?php

namespace App\Http\Resources\Api\V1\Admin\EstimatePosition;

use Illuminate\Http\Resources\Json\JsonResource;

class PriceHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'catalog_item_id' => $this->catalog_item_id,
            'user_id' => $this->user_id,
            'old_price' => (float) $this->old_price,
            'new_price' => (float) $this->new_price,
            'price_change_absolute' => $this->getPriceChangeAbsolute(),
            'price_change_percent' => round($this->getPriceChangePercent(), 2),
            'is_increase' => $this->isPriceIncrease(),
            'is_decrease' => $this->isPriceDecrease(),
            'change_reason' => $this->change_reason,
            'changed_at' => $this->changed_at?->toISOString(),
            'metadata' => $this->metadata,
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ] : null;
            }),
        ];
    }
}

