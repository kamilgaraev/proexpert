<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;

class SupplierProposalVersionService
{
    public function __construct(
        private readonly ProcurementAuditService $auditService
    ) {}

    public function createInitialVersion(SupplierProposal $proposal, ?int $actorId = null): SupplierProposalVersion
    {
        $proposal->loadMissing(['lines', 'intake']);

        $version = SupplierProposalVersion::query()->create([
            'organization_id' => $proposal->organization_id,
            'supplier_proposal_id' => $proposal->id,
            'version_number' => 1,
            'commercial_snapshot' => $this->commercialSnapshot($proposal),
            'attachment_snapshot' => $this->attachmentSnapshot($proposal),
            'created_by' => $actorId,
        ]);

        $this->auditService->record(
            ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_VERSION_CREATED->value,
            $proposal,
            (int) $proposal->organization_id,
            $actorId,
            $proposal->supplier_party_id,
            [
                'proposal_number' => $proposal->proposal_number,
                'version_number' => $version->version_number,
                'total_amount' => (float) $proposal->total_amount,
                'currency' => $proposal->currency,
            ]
        );

        return $version;
    }

    public function commercialSnapshot(SupplierProposal $proposal): array
    {
        return [
            'proposal_number' => $proposal->proposal_number,
            'proposal_date' => $proposal->proposal_date?->format('Y-m-d'),
            'subtotal_amount' => (float) $proposal->subtotal_amount,
            'delivery_amount' => (float) $proposal->delivery_amount,
            'vat_amount' => (float) $proposal->vat_amount,
            'total_amount' => (float) $proposal->total_amount,
            'currency' => $proposal->currency,
            'vat_mode' => $proposal->vat_mode,
            'vat_rate' => $proposal->vat_rate === null ? null : (float) $proposal->vat_rate,
            'delivery_terms' => $proposal->delivery_terms,
            'payment_terms' => $proposal->payment_terms,
            'warranty_terms' => $proposal->warranty_terms,
            'valid_until' => $proposal->valid_until?->format('Y-m-d'),
            'delivery_due_date' => $proposal->delivery_due_date?->format('Y-m-d'),
            'lead_time_days' => $proposal->lead_time_days,
            'lines' => $proposal->lines->map(fn ($line): array => [
                'id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'total_amount' => (float) $line->total_amount,
                'comment' => $line->comment,
            ])->values()->all(),
        ];
    }

    private function attachmentSnapshot(SupplierProposal $proposal): array
    {
        $attachmentIds = $proposal->intake?->attachment_ids;

        return [
            'intake_attachment_ids' => is_array($attachmentIds) ? array_values($attachmentIds) : [],
        ];
    }
}
