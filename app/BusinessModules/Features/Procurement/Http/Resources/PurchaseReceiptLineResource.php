<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_receipt_id' => $this->purchase_receipt_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'quantity_received' => (float) $this->quantity_received,
            'price' => (float) $this->price,
            'total_amount' => (float) $this->total_amount,
            'metadata' => $this->metadata,
            'purchase_order_item' => $this->whenLoaded(
                'purchaseOrderItem',
                fn() => $this->purchaseOrderItem ? new PurchaseOrderItemResource($this->purchaseOrderItem) : null
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
