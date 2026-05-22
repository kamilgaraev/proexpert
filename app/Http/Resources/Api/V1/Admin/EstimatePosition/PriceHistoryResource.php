<?php

namespace App\Http\Resources\Api\V1\Admin\EstimatePosition;

use App\Http\Resources\ModelJsonResource;
use App\Models\EstimatePositionPriceHistory;

class PriceHistoryResource extends ModelJsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $history = $this->typedResource(EstimatePositionPriceHistory::class);

        return [
            'id' => $this->id,
            'catalog_item_id' => $this->catalog_item_id,
            'user_id' => $this->user_id,
            'old_price' => (float) $this->old_price,
            'new_price' => (float) $this->new_price,
            'price_change_absolute' => $history->getPriceChangeAbsolute(),
            'price_change_percent' => round($history->getPriceChangePercent(), 2),
            'is_increase' => $history->isPriceIncrease(),
            'is_decrease' => $history->isPriceDecrease(),
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

