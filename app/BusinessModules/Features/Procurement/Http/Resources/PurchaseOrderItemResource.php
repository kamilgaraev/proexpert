<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof PurchaseOrderItem);

        $item = $this->resource;
        $receivedQuantity = $item->relationLoaded('receiptLines')
            ? (float) $item->receiptLines->sum('quantity_received')
            : null;

        return [
            'id' => $item->id,
            'purchase_order_id' => $item->purchase_order_id,
            'material_id' => $item->material_id,
            'material_name' => $item->material_name,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'unit_price' => (float) $item->unit_price,
            'total_price' => (float) $item->total_price,
            'received_quantity' => $receivedQuantity,
            'remaining_quantity' => $receivedQuantity === null ? null : max((float) $item->quantity - $receivedQuantity, 0),
            'notes' => $item->notes,
            'metadata' => $item->metadata,
            'created_at' => $item->created_at?->toDateTimeString(),
            'updated_at' => $item->updated_at?->toDateTimeString(),
            'material' => $this->whenLoaded('material', function () use ($item) {
                return [
                    'id' => $item->material->id,
                    'name' => $item->material->name,
                    'code' => $item->material->code,
                    'category' => $item->material->category,
                ];
            }),
        ];
    }
}
