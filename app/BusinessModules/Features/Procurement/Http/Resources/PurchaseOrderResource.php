<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_request_id' => $this->purchase_request_id,
            'supplier_id' => $this->supplier_id,
            'contract_id' => $this->contract_id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date->format('Y-m-d'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'total_amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'sent_at' => $this->sent_at?->format('Y-m-d'),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d'),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_sent' => $this->canBeSent(),
            'can_be_confirmed' => $this->canBeConfirmed(),
            'has_contract' => $this->hasContract(),
            'supplier' => $this->whenLoaded('supplier', fn() => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'inn' => $this->supplier->inn,
            ]),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn() => [
                'id' => $this->purchaseRequest->id,
                'request_number' => $this->purchaseRequest->request_number,
            ]),
            'contract' => $this->whenLoaded('contract', fn() => $this->contract ? [
                'id' => $this->contract->id,
                'number' => $this->contract->number,
            ] : null),
            'proposals' => $this->whenLoaded('proposals', fn() => SupplierProposalResource::collection($this->proposals)),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

