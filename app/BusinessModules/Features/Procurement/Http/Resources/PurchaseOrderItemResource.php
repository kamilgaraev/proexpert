<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'material_id' => $this->material_id,
            'material_name' => $this->material_name,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            
            // Relationships
            'material' => $this->whenLoaded('material', function () {
                return [
                    'id' => $this->material->id,
                    'name' => $this->material->name,
                    'code' => $this->material->code,
                    'category' => $this->material->category,
                ];
            }),
        ];
    }
}

