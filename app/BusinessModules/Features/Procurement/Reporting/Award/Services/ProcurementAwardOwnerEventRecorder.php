<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardOwnerEventWriter;
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardSelectionSource;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPreparedSelection;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardSelectionFact;
use DateTimeImmutable;
use DomainException;
use LogicException;

final class ProcurementAwardOwnerEventRecorder implements ProcurementAwardOwnerEventWriter
{
    public function __construct(
        private readonly ProcurementAwardManifestBuilder $manifestBuilder,
        private readonly ProcurementAwardEvidenceRecorder $evidenceRecorder,
        private readonly ProcurementAwardSelectionSource $selectionSource,
    ) {}

    public function prepareForSupplierRequest(
        SupplierRequest $supplierRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection {
        return $this->prepare(
            organizationId: (int) $supplierRequest->organization_id,
            supplierRequestId: (int) $supplierRequest->id,
            selectedProposalId: $selectedProposalId,
            occurredAt: $occurredAt,
        );
    }

    public function prepareForPurchaseRequest(
        PurchaseRequest $purchaseRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection {
        $supplierRequestIds = $this->selectionSource->supplierRequestIds(
            (int) $purchaseRequest->organization_id,
            (int) $purchaseRequest->id,
            $occurredAt,
        );

        if (count($supplierRequestIds) !== 1) {
            throw new DomainException('procurement_award_purchase_request_round_not_supported');
        }

        return $this->prepare(
            organizationId: (int) $purchaseRequest->organization_id,
            supplierRequestId: $supplierRequestIds[0],
            selectedProposalId: $selectedProposalId,
            occurredAt: $occurredAt,
        );
    }

    public function selected(
        ProcurementAwardPreparedSelection $prepared,
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        ?string $reason,
    ): void {
        if ((int) $decision->organization_id !== $prepared->organizationId
            || (int) $decision->supplier_request_id !== $prepared->supplierRequestId
            || ! $decision->status instanceof SupplierProposalDecisionEnum
            || (int) $decision->winning_supplier_proposal_id !== $prepared->manifest->selectedProposalId
            || ($decision->winning_supplier_proposal_version_id === null
                ? null
                : (int) $decision->winning_supplier_proposal_version_id)
                !== $prepared->manifest->selectedProposalVersionId
            || ! in_array($decision->status, [
                SupplierProposalDecisionEnum::SELECTED,
                SupplierProposalDecisionEnum::APPROVAL_REQUIRED,
            ], true)) {
            throw new LogicException('procurement_award_decision_lineage_mismatch');
        }

        $this->evidenceRecorder->captureSelection(ProcurementAwardSelectionFact::create(
            organizationId: $prepared->organizationId,
            projectId: $prepared->projectId,
            purchaseRequestId: $prepared->purchaseRequestId,
            supplierRequestId: $prepared->supplierRequestId,
            supplierRequestVersionId: $prepared->supplierRequestVersionId,
            supplierRequestVersionHash: $prepared->supplierRequestVersionHash,
            decisionId: (int) $decision->id,
            selectedStatus: $decision->status->value,
            occurredAt: $occurredAt,
            actorId: $actorId,
            manifest: $prepared->manifest,
            policy: ProcurementAwardPolicyDefinition::v1(),
            reason: $reason,
        ));
    }

    public function approved(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {
        if ($decision->status !== SupplierProposalDecisionEnum::APPROVED) {
            throw new LogicException('procurement_award_approval_state_mismatch');
        }
        $this->evidenceRecorder->approve((int) $decision->id, $occurredAt, $actorId);
    }

    public function rejected(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {
        if ($decision->status !== SupplierProposalDecisionEnum::REJECTED) {
            throw new LogicException('procurement_award_rejection_state_mismatch');
        }
        $this->evidenceRecorder->reject((int) $decision->id, $occurredAt, $actorId);
    }

    public function committed(
        SupplierProposalDecision $decision,
        SupplierProposalVersion $acceptedVersion,
        PurchaseOrder $order,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {
        $winningProposalId = (int) $decision->winning_supplier_proposal_id;
        $winningVersionId = $decision->winning_supplier_proposal_version_id === null
            ? null
            : (int) $decision->winning_supplier_proposal_version_id;
        if (! in_array($decision->status, [
            SupplierProposalDecisionEnum::SELECTED,
            SupplierProposalDecisionEnum::APPROVED,
        ], true)
            || $winningVersionId !== (int) $acceptedVersion->id
            || (int) $order->organization_id !== (int) $decision->organization_id
            || (int) $order->accepted_supplier_proposal_id !== $winningProposalId
            || (int) $order->accepted_supplier_proposal_version_id !== $winningVersionId) {
            throw new LogicException('procurement_award_commit_owner_state_mismatch');
        }
        $this->evidenceRecorder->commit(
            decisionId: (int) $decision->id,
            purchaseOrderId: (int) $order->id,
            acceptedProposalId: $winningProposalId,
            acceptedProposalVersionId: (int) $acceptedVersion->id,
            occurredAt: $occurredAt,
            actorId: $actorId,
        );
    }

    private function prepare(
        int $organizationId,
        int $supplierRequestId,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection {
        $candidateRows = $this->selectionSource->candidateRows(
            $organizationId,
            $supplierRequestId,
            $occurredAt,
        );
        $manifest = $this->manifestBuilder->build($candidateRows, $selectedProposalId);
        $selected = null;
        foreach ($manifest->candidates as $candidate) {
            if ($candidate->proposalId === $selectedProposalId) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            throw new LogicException('procurement_award_selected_candidate_missing');
        }

        return new ProcurementAwardPreparedSelection(
            organizationId: $selected->organizationId,
            projectId: $selected->projectId,
            purchaseRequestId: $selected->purchaseRequestId,
            supplierRequestId: $selected->supplierRequestId,
            supplierRequestVersionId: $selected->supplierRequestVersionId,
            supplierRequestVersionHash: $selected->supplierRequestVersionHash,
            manifest: $manifest,
        );
    }
}
