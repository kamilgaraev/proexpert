<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_request_id' => $this->purchase_request_id,
            'supplier_id' => $this->supplier_id,
            'external_supplier_contact_id' => $this->external_supplier_contact_id,
            'supplier_party_id' => $this->supplier_party_id,
            'supplier_snapshot' => $this->supplier_snapshot,
            'request_number' => $this->request_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'comment' => $this->comment,
            'metadata' => $this->metadata,
            'can_be_sent' => $this->canBeSent(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'phone' => $this->supplier->phone,
                'email' => $this->supplier->email,
                'tax_number' => $this->supplier->tax_number,
            ] : null),
            'external_supplier_contact' => $this->whenLoaded(
                'externalSupplierContact',
                fn () => $this->externalSupplierContact
                    ? new ExternalSupplierContactResource($this->externalSupplierContact)
                    : null
            ),
            'supplier_party' => $this->whenLoaded(
                'supplierParty',
                fn () => $this->supplierParty ? new SupplierPartyResource($this->supplierParty) : null
            ),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => [
                'id' => $this->purchaseRequest->id,
                'request_number' => $this->purchaseRequest->request_number,
                'status' => $this->purchaseRequest->status->value,
                'status_label' => $this->purchaseRequest->status->label(),
            ]),
            'lines' => $this->whenLoaded('lines', fn () => SupplierRequestLineResource::collection($this->lines)),
            'audit_events' => $this->whenLoaded(
                'auditEvents',
                fn () => ProcurementAuditEventResource::collection($this->auditEvents)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
