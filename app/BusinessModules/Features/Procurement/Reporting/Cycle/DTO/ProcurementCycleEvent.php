<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use InvalidArgumentException;

final readonly class ProcurementCycleEvent
{
    public function __construct(
        public int $id,
        public ProcurementProcessTransition $transition,
    ) {
        if ($this->id < 1) {
            throw new InvalidArgumentException('procurement_cycle_event_invalid');
        }
    }

    public function auditPayload(): array
    {
        return [
            'event_id' => $this->id,
            'event_code' => $this->transition->eventCode->value,
            'occurred_at' => $this->transition->occurredAtUtc(),
            'actor_id' => $this->transition->actorId,
            'source_kind' => $this->transition->sourceKind,
            'source_id' => $this->transition->sourceId,
            'source_event_id' => $this->transition->sourceEventId,
            'supplier_request_id' => $this->transition->supplierRequestId,
            'supplier_request_line_id' => $this->transition->supplierRequestLineId,
            'supplier_party_id' => $this->transition->supplierPartyId,
            'supplier_proposal_id' => $this->transition->supplierProposalId,
            'supplier_proposal_version_id' => $this->transition->supplierProposalVersionId,
            'supplier_proposal_decision_id' => $this->transition->supplierProposalDecisionId,
            'purchase_order_id' => $this->transition->purchaseOrderId,
            'purchase_order_item_id' => $this->transition->purchaseOrderItemId,
            'purchase_receipt_id' => $this->transition->purchaseReceiptId,
            'purchase_receipt_line_id' => $this->transition->purchaseReceiptLineId,
        ];
    }
}
