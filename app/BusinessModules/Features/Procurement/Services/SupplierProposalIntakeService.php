<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierPartyTypeEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalIntake;
use Illuminate\Validation\ValidationException;

use function trans_message;

class SupplierProposalIntakeService
{
    public function __construct(
        private readonly ProcurementAuditService $auditService
    ) {}

    public function recordForProposal(SupplierProposal $proposal, array $data, ?int $actorId = null): ?SupplierProposalIntake
    {
        $proposal->loadMissing('supplierParty');
        $intake = $data['intake'] ?? null;
        $isExternal = $proposal->supplierParty?->type === SupplierPartyTypeEnum::EXTERNAL;

        if (!$isExternal && !is_array($intake)) {
            return null;
        }

        if ($isExternal) {
            $this->assertExternalIntakeIsComplete(is_array($intake) ? $intake : []);
        }

        if (!is_array($intake)) {
            return null;
        }

        $attachmentIds = array_values(array_filter($intake['attachment_ids'] ?? [], static fn ($id): bool => $id !== null && $id !== ''));

        $record = SupplierProposalIntake::query()->create([
            'organization_id' => $proposal->organization_id,
            'supplier_proposal_id' => $proposal->id,
            'supplier_party_id' => $proposal->supplier_party_id,
            'source' => $intake['source'],
            'received_at' => $intake['received_at'] ?? now(),
            'entered_by' => $actorId,
            'external_reference' => $this->nullableString($intake['external_reference'] ?? null),
            'comment' => $this->nullableString($intake['comment'] ?? null),
            'attachment_ids' => $attachmentIds,
        ]);

        $this->auditService->record(
            ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_INTAKE_RECORDED->value,
            $proposal,
            (int) $proposal->organization_id,
            $actorId,
            $proposal->supplier_party_id,
            [
                'proposal_number' => $proposal->proposal_number,
                'source' => $record->source->value,
                'received_at' => $record->received_at?->toIso8601String(),
                'external_reference' => $record->external_reference,
                'has_attachments' => $attachmentIds !== [],
            ]
        );

        return $record;
    }

    private function assertExternalIntakeIsComplete(array $intake): void
    {
        $source = $this->nullableString($intake['source'] ?? null);
        $reference = $this->nullableString($intake['external_reference'] ?? null);
        $comment = $this->nullableString($intake['comment'] ?? null);
        $attachments = array_values(array_filter($intake['attachment_ids'] ?? [], static fn ($id): bool => $id !== null && $id !== ''));

        if ($source === null) {
            throw ValidationException::withMessages([
                'intake.source' => [trans_message('procurement_enterprise.proposals.intake_source_required')],
            ]);
        }

        if ($this->nullableString($intake['received_at'] ?? null) === null) {
            throw ValidationException::withMessages([
                'intake.received_at' => [trans_message('procurement_enterprise.proposals.intake_received_at_required')],
            ]);
        }

        if ($reference === null && $comment === null && $attachments === []) {
            throw ValidationException::withMessages([
                'intake' => [trans_message('procurement_enterprise.proposals.intake_evidence_required')],
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
