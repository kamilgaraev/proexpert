<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_order_id' => $this->purchase_order_id,
            'warehouse_id' => $this->warehouse_id,
            'received_by_user_id' => $this->received_by_user_id,
            'receipt_number' => $this->receipt_number,
            'receipt_date' => $this->receipt_date?->format('Y-m-d'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'procurement_chain_summary' => app(ProcurementChainService::class)
                ->forPurchaseReceipt($this->resource, $request->user())
                ->compact()
                ->toArray(),
            'warehouse' => $this->whenLoaded('warehouse', fn() => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'received_by_user' => $this->whenLoaded('receivedByUser', fn() => $this->receivedByUser ? [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->name,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn() => PurchaseReceiptLineResource::collection($this->lines)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
