<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workflowSummary = app(ProcurementLifecycleService::class)
            ->forPurchaseOrder($this->resource);
        $paymentSummary = app(PurchaseOrderPaymentGateService::class)
            ->summary($this->resource);
        $chainSummary = app(ProcurementChainService::class)
            ->forPurchaseOrder($this->resource, $request->user());

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'purchase_request_id' => $this->purchase_request_id,
            'accepted_supplier_proposal_id' => $this->accepted_supplier_proposal_id,
            'accepted_supplier_proposal_version_id' => $this->accepted_supplier_proposal_version_id,
            'supplier_id' => $this->supplier_id,
            'external_supplier_contact_id' => $this->external_supplier_contact_id,
            'supplier_party_id' => $this->supplier_party_id,
            'supplier_snapshot' => $this->supplier_snapshot,
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
            'can_receive_materials' => $workflowSummary->canReceiveMaterials,
            'workflow_summary' => $workflowSummary->toArray(),
            'payment_summary' => $paymentSummary,
            'procurement_chain_summary' => $chainSummary->compact()->toArray(),
            'has_contract' => $this->hasContract(),
            'supplier' => $this->supplierPayload(),
            'external_supplier_contact' => $this->whenLoaded(
                'externalSupplierContact',
                fn () => $this->externalSupplierContact ? new ExternalSupplierContactResource($this->externalSupplierContact) : null
            ),
            'supplier_party' => $this->whenLoaded(
                'supplierParty',
                fn () => $this->supplierParty ? new SupplierPartyResource($this->supplierParty) : null
            ),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => [
                'id' => $this->purchaseRequest->id,
                'request_number' => $this->purchaseRequest->request_number,
            ]),
            'contract' => $this->whenLoaded('contract', fn () => $this->contract ? [
                'id' => $this->contract->id,
                'number' => $this->contract->number,
            ] : null),
            'proposals' => $this->whenLoaded('proposals', fn () => SupplierProposalResource::collection($this->proposals)),
            'accepted_supplier_proposal_version' => $this->whenLoaded('acceptedSupplierProposalVersion', fn () => $this->acceptedSupplierProposalVersion ? [
                'id' => $this->acceptedSupplierProposalVersion->id,
                'version_number' => $this->acceptedSupplierProposalVersion->version_number,
                'commercial_snapshot' => $this->acceptedSupplierProposalVersion->commercial_snapshot,
                'created_at' => $this->acceptedSupplierProposalVersion->created_at?->toIso8601String(),
            ] : null),
            'items' => $this->whenLoaded('items', fn () => PurchaseOrderItemResource::collection($this->items)),
            'receipts' => $this->whenLoaded('receipts', fn () => PurchaseReceiptResource::collection($this->receipts)),
            'audit_events' => $this->whenLoaded(
                'auditEvents',
                fn () => ProcurementAuditEventResource::collection($this->auditEvents)
            ),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function supplierPayload(): array
    {
        $snapshot = is_array($this->supplier_snapshot) ? $this->supplier_snapshot : [];
        $supplier = $this->relationLoaded('supplier') ? $this->supplier : null;
        $externalSupplierContact = $this->relationLoaded('externalSupplierContact')
            ? $this->externalSupplierContact
            : null;
        $supplierParty = $this->relationLoaded('supplierParty') ? $this->supplierParty : null;
        $supplierId = array_key_exists('registered_supplier_id', $snapshot)
            ? $snapshot['registered_supplier_id']
            : $this->supplier_id;

        return [
            'id' => is_numeric($supplierId) ? (int) $supplierId : null,
            'party_id' => $this->supplier_party_id,
            'name' => $this->firstFilledString(
                $snapshot['display_name'] ?? null,
                $snapshot['name'] ?? null,
                $supplierParty?->display_name,
                $externalSupplierContact?->name,
                $supplier?->name
            ) ?? 'Внешний поставщик',
            'inn' => $this->firstFilledString(
                $snapshot['tax_id'] ?? null,
                $supplierParty?->tax_id,
                $externalSupplierContact?->tax_number,
                $supplier?->inn,
                $supplier?->tax_number
            ),
        ];
    }

    private function firstFilledString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
