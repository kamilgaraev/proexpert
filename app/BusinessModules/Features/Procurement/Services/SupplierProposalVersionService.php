<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;

class SupplierProposalVersionService
{
    public function __construct(
        private readonly ProcurementAuditService $auditService
    ) {}

    public function createInitialVersion(SupplierProposal $proposal, ?int $actorId = null): SupplierProposalVersion
    {
        $proposal->loadMissing(['lines', 'intake']);

        $commercialSnapshot = $this->commercialSnapshot($proposal);
        $version = SupplierProposalVersion::query()->create([
            'organization_id' => $proposal->organization_id,
            'supplier_proposal_id' => $proposal->id,
            'version_number' => 1,
            'commercial_snapshot' => $commercialSnapshot,
            'attachment_snapshot' => $this->attachmentSnapshot($proposal),
            'content_hash' => $this->contentHash($commercialSnapshot),
            'integrity_status' => 'verified',
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
        $lines = $proposal->lines
            ->sortBy([
                ['supplier_request_line_id', 'asc'],
                ['id', 'asc'],
            ])
            ->map(static fn ($line): array => [
                'id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => ProcurementAwardCanonicalizer::decimal($line->quantity),
                'unit' => $line->unit,
                'unit_price' => ProcurementAwardCanonicalizer::decimal($line->unit_price),
                'total_amount' => ProcurementAwardCanonicalizer::decimal($line->total_amount),
            ])
            ->values()
            ->all();

        return [
            'proposal_number' => $proposal->proposal_number,
            'proposal_date' => $proposal->proposal_date?->format('Y-m-d'),
            'subtotal_amount' => ProcurementAwardCanonicalizer::decimal($proposal->subtotal_amount),
            'delivery_amount' => ProcurementAwardCanonicalizer::decimal($proposal->delivery_amount),
            'vat_amount' => ProcurementAwardCanonicalizer::decimal($proposal->vat_amount),
            'total_amount' => ProcurementAwardCanonicalizer::decimal($proposal->total_amount),
            'currency' => strtoupper((string) $proposal->currency),
            'vat_mode' => $proposal->vat_mode,
            'vat_rate' => $proposal->vat_rate === null
                ? null
                : ProcurementAwardCanonicalizer::decimal($proposal->vat_rate),
            'valid_until' => $proposal->valid_until?->format('Y-m-d'),
            'delivery_due_date' => $proposal->delivery_due_date?->format('Y-m-d'),
            'lead_time_days' => $proposal->lead_time_days,
            'lines' => $lines,
        ];
    }

    public function contentHash(array $commercialSnapshot): string
    {
        return ProcurementAwardCanonicalizer::hash($commercialSnapshot);
    }

    private function attachmentSnapshot(SupplierProposal $proposal): array
    {
        $attachmentIds = $proposal->intake?->attachment_ids;

        return [
            'intake_attachment_ids' => is_array($attachmentIds) ? array_values($attachmentIds) : [],
        ];
    }
}
