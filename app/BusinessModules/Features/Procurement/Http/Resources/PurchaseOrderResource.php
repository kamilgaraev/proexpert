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
            'accepted_supplier_proposal_id' => $this->accepted_supplier_proposal_id,
            'supplier_id' => $this->supplier_id,
            'external_supplier_contact_id' => $this->external_supplier_contact_id,
            'contract_id' => $this->contract_id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date->format('Y-m-d'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'total_amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'pricing_source' => $this->pricing_source,
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'sent_at' => $this->sent_at?->format('Y-m-d'),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d'),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_sent' => $this->canBeSent(),
            'can_be_confirmed' => $this->canBeConfirmed(),
            'can_receive_materials' => $this->status->canReceiveMaterials(),
            'has_contract' => $this->hasContract(),
            'supplier' => $this->supplierPayload(),
            'external_supplier_contact' => $this->whenLoaded(
                'externalSupplierContact',
                fn() => $this->externalSupplierContact ? new ExternalSupplierContactResource($this->externalSupplierContact) : null
            ),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn() => [
                'id' => $this->purchaseRequest->id,
                'request_number' => $this->purchaseRequest->request_number,
            ]),
            'contract' => $this->whenLoaded('contract', fn() => $this->contract ? [
                'id' => $this->contract->id,
                'number' => $this->contract->number,
            ] : null),
            'proposals' => $this->whenLoaded('proposals', fn() => SupplierProposalResource::collection($this->proposals)),
            'items' => $this->whenLoaded('items', fn() => PurchaseOrderItemResource::collection($this->items)),
            'receipts' => $this->whenLoaded('receipts', fn() => PurchaseReceiptResource::collection($this->receipts)),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function supplierPayload(): array
    {
        if ($this->supplier) {
            return [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'inn' => $this->supplier->inn,
            ];
        }

        return [
            'id' => null,
            'name' => $this->externalSupplierContact?->name ?? 'Внешний поставщик',
            'inn' => $this->externalSupplierContact?->tax_number,
        ];
    }
}

