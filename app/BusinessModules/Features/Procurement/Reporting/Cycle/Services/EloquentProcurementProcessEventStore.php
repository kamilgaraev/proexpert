<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentProcurementProcessEventStore implements ProcurementProcessEventStore
{
    public function __construct(private readonly ProcurementEventIdempotencyGuard $idempotency)
    {
    }

    public function append(ProcurementProcessTransition $transition): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('procurement_process_event_owner_transaction_required');
        }

        $lineage = DB::table('purchase_request_lines')
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
            ->where('purchase_request_lines.id', $transition->purchaseRequestLineId)
            ->where('purchase_requests.id', $transition->purchaseRequestId)
            ->where('purchase_requests.organization_id', $transition->organizationId)
            ->lockForUpdate()
            ->first(['purchase_request_lines.id']);
        if ($lineage === null) {
            throw new LogicException('procurement_process_event_lineage_mismatch');
        }

        $identity = $transition->idempotencyIdentity();
        $existing = DB::table('procurement_process_events')
            ->where($identity)
            ->lockForUpdate()
            ->first(['payload_hash']);
        if ($existing !== null) {
            $existingHash = is_string($existing->payload_hash) ? $existing->payload_hash : '';
            $this->idempotency->isExactReplay($existingHash, $transition->payloadHash());

            return;
        }

        $lastOccurredAt = DB::table('procurement_process_events')
            ->where('organization_id', $transition->organizationId)
            ->where('purchase_request_line_id', $transition->purchaseRequestLineId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('occurred_at');
        if ($lastOccurredAt !== null
            && new \DateTimeImmutable((string) $lastOccurredAt) > $transition->occurredAt) {
            throw new LogicException('procurement_process_event_time_regression');
        }

        $inserted = DB::table('procurement_process_events')->insertOrIgnore([
            ...$identity,
            'project_id' => $transition->projectId,
            'purchase_request_id' => $transition->purchaseRequestId,
            'supplier_request_id' => $transition->supplierRequestId,
            'supplier_request_line_id' => $transition->supplierRequestLineId,
            'supplier_party_id' => $transition->supplierPartyId,
            'supplier_proposal_id' => $transition->supplierProposalId,
            'supplier_proposal_version_id' => $transition->supplierProposalVersionId,
            'supplier_proposal_decision_id' => $transition->supplierProposalDecisionId,
            'purchase_order_id' => $transition->purchaseOrderId,
            'purchase_order_item_id' => $transition->purchaseOrderItemId,
            'purchase_receipt_id' => $transition->purchaseReceiptId,
            'purchase_receipt_line_id' => $transition->purchaseReceiptLineId,
            'policy_version_id' => $transition->policyVersionId,
            'policy_hash' => $transition->policyHash,
            'calendar_version' => $transition->calendarVersion,
            'calendar_hash' => $transition->calendarHash,
            'terminal_reason' => $transition->terminalReason?->value,
            'actor_id' => $transition->actorId,
            'occurred_at' => $transition->occurredAtUtc(),
            'source_event_id' => $transition->sourceEventId,
            'dimension_snapshot' => CanonicalJson::encode($transition->dimensionSnapshot->values),
            'payload_hash' => $transition->payloadHash(),
            'created_at' => now(new DateTimeZone('UTC')),
        ]);

        if ($inserted === 1) {
            return;
        }

        $existing = DB::table('procurement_process_events')
            ->where($identity)
            ->lockForUpdate()
            ->first(['payload_hash']);
        $existingHash = $existing !== null && is_string($existing->payload_hash)
            ? $existing->payload_hash
            : null;
        if (! $this->idempotency->isExactReplay($existingHash, $transition->payloadHash())) {
            throw new LogicException('procurement_process_event_idempotency_conflict');
        }
    }
}
