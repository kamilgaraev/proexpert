<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierProposal */
class SupplierProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workflowSummary = app(ProcurementLifecycleService::class)
            ->forSupplierProposal($this->resource);
        $proposalDecision = $this->proposalDecisionResource();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_request_id' => $this->supplier_request_id,
            'supplier_request_version_id' => $this->supplier_request_version_id,
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
            'vat_mode' => $this->vat_mode,
            'vat_rate' => $this->vat_rate === null ? null : (float) $this->vat_rate,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'delivery_due_date' => $this->delivery_due_date?->format('Y-m-d'),
            'lead_time_days' => $this->lead_time_days,
            'payment_terms' => $this->payment_terms,
            'delivery_terms' => $this->delivery_terms,
            'warranty_terms' => $this->warranty_terms,
            'items' => $this->items,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_accepted' => $workflowSummary->canAcceptProposal,
            'workflow_summary' => $workflowSummary->toArray(),
            'decision' => $proposalDecision,
            'proposal_decision' => $proposalDecision,
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
            'intake' => $this->whenLoaded('intake', fn() => $this->intake ? [
                'id' => $this->intake->id,
                'source' => $this->intake->source->value,
                'received_at' => $this->intake->received_at?->toIso8601String(),
                'entered_by' => $this->intake->entered_by,
                'external_reference' => $this->intake->external_reference,
                'comment' => $this->intake->comment,
                'attachment_ids' => $this->intake->attachment_ids ?? [],
            ] : null),
            'current_version' => $this->whenLoaded('currentVersion', fn() => $this->currentVersion ? [
                'id' => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'commercial_snapshot' => $this->currentVersion->commercial_snapshot,
                'attachment_snapshot' => $this->currentVersion->attachment_snapshot,
                'created_by' => $this->currentVersion->created_by,
                'created_at' => $this->currentVersion->created_at?->toIso8601String(),
            ] : null),
            'supplier_request' => $this->whenLoaded('supplierRequest', fn() => $this->supplierRequest ? [
                'id' => $this->supplierRequest->id,
                'request_number' => $this->supplierRequest->request_number,
                'status' => $this->supplierRequest->status->value,
            ] : null),
            'supplier_request_version' => $this->whenLoaded('supplierRequestVersion', fn() => $this->supplierRequestVersion ? [
                'id' => $this->supplierRequestVersion->id,
                'version_number' => $this->supplierRequestVersion->version_number,
                'sent_at' => $this->supplierRequestVersion->sent_at?->toIso8601String(),
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
            'audit_events' => $this->whenLoaded(
                'auditEvents',
                fn() => ProcurementAuditEventResource::collection($this->auditEvents)
            ),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function supplierPayload(): array
    {
        $snapshot = is_array($this->supplier_snapshot) ? $this->supplier_snapshot : [];

        if ($snapshot !== []) {
            return [
                'id' => $snapshot['registered_supplier_id'] ?? $this->supplier_id,
                'name' => $snapshot['display_name'] ?? 'Внешний поставщик',
                'inn' => $snapshot['tax_id'] ?? null,
            ];
        }

        if ($this->relationLoaded('supplier') && $this->supplier) {
            return [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'inn' => $this->supplier->inn,
            ];
        }

        $externalSupplierContact = $this->relationLoaded('externalSupplierContact')
            ? $this->externalSupplierContact
            : null;

        return [
            'id' => null,
            'name' => $externalSupplierContact?->name ?? 'Внешний поставщик',
            'inn' => $externalSupplierContact?->tax_number,
        ];
    }

    private function proposalDecisionResource(): ?SupplierProposalDecisionResource
    {
        if (
            !$this->relationLoaded('supplierRequest')
            || $this->supplierRequest === null
            || !$this->supplierRequest->relationLoaded('proposalDecision')
            || $this->supplierRequest->proposalDecision === null
        ) {
            return null;
        }

        return new SupplierProposalDecisionResource($this->supplierRequest->proposalDecision);
    }
}
