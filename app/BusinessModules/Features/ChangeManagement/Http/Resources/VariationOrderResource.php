<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Resources;

use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VariationOrder */
final class VariationOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var VariationOrder $variationOrder */
        $variationOrder = $this->resource;

        return [
            'id' => $variationOrder->id,
            'organization_id' => $variationOrder->organization_id,
            'change_request_id' => $variationOrder->change_request_id,
            'variation_number' => $variationOrder->variation_number,
            'amount' => $variationOrder->amount,
            'schedule_delta_days' => $variationOrder->schedule_delta_days,
            'description' => $variationOrder->description,
            'created_at' => $variationOrder->created_at?->toIso8601String(),
            'updated_at' => $variationOrder->updated_at?->toIso8601String(),
        ];
    }
}
