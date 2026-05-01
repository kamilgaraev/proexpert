<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_request_id' => $this->supplier_request_id,
            'supplier_id' => $this->supplier_id,
            'external_supplier_contact_id' => $this->external_supplier_contact_id,
            'supplier_party_id' => $this->supplier_party_id,
            'supplier_snapshot' => $this->supplier_snapshot,
            'proposal_number' => $this->proposal_number,
            'proposal_date' => $this->proposal_date->format('Y-m-d'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'subtotal_amount' => (float) $this->subtotal_amount,
            'delivery_amount' => (float) $this->delivery_amount,
            'vat_amount' => (float) $this->vat_amount,
            'total_amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'payment_terms' => $this->payment_terms,
            'delivery_terms' => $this->delivery_terms,
            'items' => $this->items,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_accepted' => $this->canBeAccepted(),
            'is_expired' => $this->isExpired(),
            'supplier' => $this->supplierPayload(),
            'external_supplier_contact' => $this->whenLoaded(
                'externalSupplierContact',
                fn() => $this->externalSupplierContact ? new ExternalSupplierContactResource($this->externalSupplierContact) : null
            ),
            'supplier_party' => $this->whenLoaded(
                'supplierParty',
                fn() => $this->supplierParty ? new SupplierPartyResource($this->supplierParty) : null
            ),
            'supplier_request' => $this->whenLoaded('supplierRequest', fn() => $this->supplierRequest ? [
                'id' => $this->supplierRequest->id,
                'request_number' => $this->supplierRequest->request_number,
                'status' => $this->supplierRequest->status->value,
            ] : null),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn() => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->id,
                'order_number' => $this->purchaseOrder->order_number,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn() => $this->lines->map(fn($line) => [
                'id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'total_amount' => (float) $line->total_amount,
                'comment' => $line->comment,
            ])),
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
