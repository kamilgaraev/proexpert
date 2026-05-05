<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\DTOs\ProcurementLifecycleSummary;
use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProcurementLifecycleService
{
    public function forPurchaseRequest(PurchaseRequest $purchaseRequest): ProcurementLifecycleSummary
    {
        $purchaseRequest->loadMissing([
            'lines',
            'supplierRequests.proposals',
            'supplierRequests.proposalDecision',
            'purchaseOrders.items',
        ]);

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::DRAFT) {
            return $this->summary('request_draft', null);
        }

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::PENDING) {
            return $this->summary('request_pending', 'approve_request');
        }

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::REJECTED) {
            return $this->summary('request_rejected', null);
        }

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::CANCELLED) {
            return $this->summary('request_cancelled', null);
        }

        $this->syncLoadedSupplierRequests($purchaseRequest);

        $order = $this->latestOrder($purchaseRequest);
        if ($order instanceof PurchaseOrder) {
            return $this->forPurchaseOrder($order);
        }

        $supplierRequests = $purchaseRequest->supplierRequests
            ->filter(static fn (SupplierRequest $request): bool => !in_array($request->status, [
                SupplierRequestStatusEnum::CANCELLED,
                SupplierRequestStatusEnum::EXPIRED,
            ], true))
            ->values();

        if ($supplierRequests->isEmpty()) {
            return $this->summary('approved_without_supplier_requests', 'create_supplier_request', [
                'canCreateSupplierRequest' => true,
            ]);
        }

        $respondedRequest = $supplierRequests->first(
            static fn (SupplierRequest $request): bool => $request->status === SupplierRequestStatusEnum::RESPONDED
        );

        if ($respondedRequest instanceof SupplierRequest) {
            $decision = $respondedRequest->proposalDecision;
            if ($this->decisionAllowsAcceptance($decision)) {
                return $this->summary('proposal_selected', 'accept_proposal', [
                    'canAcceptProposal' => true,
                ]);
            }

            if ($decision?->status === SupplierProposalDecisionEnum::APPROVAL_REQUIRED) {
                return $this->summary('proposal_approval_required', 'wait_for_approval');
            }

            return $this->summary('proposals_received', 'select_proposal', [
                'canSelectProposal' => true,
            ]);
        }

        $draftRequest = $supplierRequests->first(
            static fn (SupplierRequest $request): bool => $request->status === SupplierRequestStatusEnum::DRAFT
        );

        if ($draftRequest instanceof SupplierRequest) {
            return $this->summary('supplier_requests_draft', 'send_supplier_request', [
                'canSendSupplierRequest' => true,
            ]);
        }

        return $this->summary('supplier_requests_sent', 'wait_for_proposals');
    }

    public function forSupplierRequest(SupplierRequest $supplierRequest): ProcurementLifecycleSummary
    {
        $supplierRequest->loadMissing(['proposals', 'proposalDecision', 'purchaseRequest']);
        $supplierRequest = $this->syncSupplierRequestExpiry($supplierRequest);

        return match ($supplierRequest->status) {
            SupplierRequestStatusEnum::DRAFT => $this->summary('supplier_request_draft', 'send_supplier_request', [
                'canSendSupplierRequest' => true,
            ]),
            SupplierRequestStatusEnum::SENT => $this->summary('supplier_request_sent', 'wait_for_proposals', [
                'canSubmitProposal' => $supplierRequest->canReceivePublicProposal(),
            ]),
            SupplierRequestStatusEnum::RESPONDED => $this->supplierRequestRespondedSummary($supplierRequest),
            SupplierRequestStatusEnum::CANCELLED => $this->summary('supplier_request_cancelled', null),
            SupplierRequestStatusEnum::EXPIRED => $this->summary('supplier_request_expired', null, [
                'blockers' => [$this->blocker('supplier_request_expired')],
            ]),
        };
    }

    public function forSupplierProposal(SupplierProposal $proposal): ProcurementLifecycleSummary
    {
        $proposal->loadMissing(['supplierRequest.proposalDecision', 'purchaseOrder']);

        if ($proposal->status === SupplierProposalStatusEnum::ACCEPTED) {
            return $this->summary('proposal_accepted', $proposal->purchase_order_id === null ? 'create_order' : null, [
                'canCreateOrder' => $proposal->purchase_order_id === null,
            ]);
        }

        if ($proposal->status === SupplierProposalStatusEnum::REJECTED) {
            return $this->summary('proposal_rejected', null);
        }

        if ($proposal->status === SupplierProposalStatusEnum::EXPIRED || $proposal->isExpired()) {
            return $this->summary('proposal_expired', null, [
                'blockers' => [$this->blocker('proposal_expired')],
            ]);
        }

        if ($proposal->status !== SupplierProposalStatusEnum::SUBMITTED) {
            return $this->summary('proposal_draft', null);
        }

        if ($this->canAcceptProposal($proposal)) {
            return $this->summary('proposal_selected', 'accept_proposal', [
                'canAcceptProposal' => true,
            ]);
        }

        return $this->summary('proposal_submitted', 'select_proposal');
    }

    public function forPurchaseOrder(PurchaseOrder $order): ProcurementLifecycleSummary
    {
        $order->loadMissing(['items']);

        return match ($order->status) {
            PurchaseOrderStatusEnum::DRAFT => $this->summary('order_draft', 'send_order'),
            PurchaseOrderStatusEnum::SENT => $this->summary('order_sent', 'confirm_order'),
            PurchaseOrderStatusEnum::CONFIRMED => $this->summary('order_confirmed', 'receive_materials', [
                'canReceiveMaterials' => true,
            ]),
            PurchaseOrderStatusEnum::IN_DELIVERY => $this->summary('order_in_delivery', 'receive_materials', [
                'canReceiveMaterials' => true,
            ]),
            PurchaseOrderStatusEnum::PARTIALLY_DELIVERED => $this->summary('order_partially_delivered', 'receive_materials', [
                'canReceiveMaterials' => true,
            ]),
            PurchaseOrderStatusEnum::DELIVERED => $this->summary('completed', null),
            PurchaseOrderStatusEnum::CANCELLED => $this->summary('order_cancelled', null),
        };
    }

    public function assertCanCreateSupplierRequest(PurchaseRequest $purchaseRequest): void
    {
        $purchaseRequest->loadMissing('lines');

        if ($purchaseRequest->status !== PurchaseRequestStatusEnum::APPROVED) {
            throw ValidationException::withMessages([
                'purchase_request_id' => trans_message('procurement.lifecycle.blockers.purchase_request_not_approved'),
            ]);
        }

        if ($purchaseRequest->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => trans_message('procurement.supplier_requests.purchase_request_lines_required'),
            ]);
        }
    }

    public function assertCanAcceptProposal(SupplierProposal $proposal): void
    {
        if (!$this->canAcceptProposal($proposal)) {
            throw new \DomainException(trans_message('procurement.proposal_decisions.accepted_decision_required'));
        }
    }

    public function syncSupplierRequestExpiry(SupplierRequest $supplierRequest): SupplierRequest
    {
        if (
            $supplierRequest->status === SupplierRequestStatusEnum::SENT
            && $supplierRequest->public_token_expires_at !== null
            && $supplierRequest->public_token_expires_at->isPast()
        ) {
            $supplierRequest->update(['status' => SupplierRequestStatusEnum::EXPIRED]);
        }

        return $supplierRequest->refresh();
    }

    public function assertCanReceiveMaterials(PurchaseOrder $order, array $items): void
    {
        if (!$order->status->canReceiveMaterials()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_receive'));
        }

        $orderItemIds = collect($items)
            ->pluck('item_id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values();

        /** @var Collection<int, PurchaseOrderItem> $orderItems */
        $orderItems = $order->items()
            ->whereIn('id', $orderItemIds)
            ->get()
            ->keyBy('id');

        if ($orderItems->count() !== $orderItemIds->count()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
        }

        $requestedByItemId = collect($items)
            ->groupBy(static fn (array $item): int => (int) $item['item_id'])
            ->map(static fn (Collection $rows): float => (float) $rows->sum('quantity_received'));

        $receivedByItemId = $this->receivedQuantitiesByItemId($order);

        foreach ($orderItems as $orderItem) {
            $orderedQuantity = (float) $orderItem->quantity;
            $alreadyReceived = (float) ($receivedByItemId[$orderItem->id] ?? 0);
            $requestedQuantity = (float) ($requestedByItemId[$orderItem->id] ?? 0);

            if ($alreadyReceived + $requestedQuantity > $orderedQuantity + 0.0001) {
                throw new \DomainException(trans_message('procurement.purchase_orders.quantity_exceeds_order'));
            }
        }
    }

    public function resolveOrderReceiptStatus(PurchaseOrder $order): PurchaseOrderStatusEnum
    {
        $order->loadMissing('items');
        $receivedByItemId = $this->receivedQuantitiesByItemId($order);

        if ($order->items->isEmpty()) {
            return $order->status;
        }

        $hasAnyReceipt = false;
        $allItemsReceived = true;

        foreach ($order->items as $item) {
            $received = (float) ($receivedByItemId[$item->id] ?? 0);
            $hasAnyReceipt = $hasAnyReceipt || $received > 0.0001;

            if ($received + 0.0001 < (float) $item->quantity) {
                $allItemsReceived = false;
            }
        }

        if ($allItemsReceived) {
            return PurchaseOrderStatusEnum::DELIVERED;
        }

        return $hasAnyReceipt
            ? PurchaseOrderStatusEnum::PARTIALLY_DELIVERED
            : $order->status;
    }

    private function supplierRequestRespondedSummary(SupplierRequest $supplierRequest): ProcurementLifecycleSummary
    {
        $decision = $supplierRequest->proposalDecision;
        if ($this->decisionAllowsAcceptance($decision)) {
            return $this->summary('proposal_selected', 'accept_proposal', [
                'canAcceptProposal' => true,
            ]);
        }

        if ($decision?->status === SupplierProposalDecisionEnum::APPROVAL_REQUIRED) {
            return $this->summary('proposal_approval_required', 'wait_for_approval');
        }

        return $this->summary('proposals_received', 'select_proposal', [
            'canSelectProposal' => true,
        ]);
    }

    private function canAcceptProposal(SupplierProposal $proposal): bool
    {
        $proposal->loadMissing(['supplierRequest.proposalDecision']);

        if ($proposal->status !== SupplierProposalStatusEnum::SUBMITTED) {
            return false;
        }

        if ($proposal->isExpired()) {
            return false;
        }

        $supplierRequest = $proposal->supplierRequest;
        if (!$supplierRequest instanceof SupplierRequest || $supplierRequest->status !== SupplierRequestStatusEnum::RESPONDED) {
            return false;
        }

        $decision = $supplierRequest->proposalDecision;

        if (!$this->decisionAllowsAcceptance($decision)) {
            return false;
        }

        if ($decision->winning_supplier_proposal_id !== $proposal->id) {
            return false;
        }

        return !DB::table('purchase_orders')
            ->where('organization_id', $proposal->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($proposal): void {
                $query->where('accepted_supplier_proposal_id', $proposal->id)
                    ->orWhereIn('accepted_supplier_proposal_id', function ($subquery) use ($proposal): void {
                        $subquery
                            ->select('id')
                            ->from('supplier_proposals')
                            ->where('organization_id', $proposal->organization_id)
                            ->where('supplier_request_id', $proposal->supplier_request_id);
                    });
            })
            ->exists();
    }

    private function decisionAllowsAcceptance(?SupplierProposalDecision $decision): bool
    {
        return $decision instanceof SupplierProposalDecision
            && in_array($decision->status, [
                SupplierProposalDecisionEnum::SELECTED,
                SupplierProposalDecisionEnum::APPROVED,
            ], true);
    }

    private function latestOrder(PurchaseRequest $purchaseRequest): ?PurchaseOrder
    {
        return $purchaseRequest->purchaseOrders
            ->sortByDesc('id')
            ->first();
    }

    private function syncLoadedSupplierRequests(PurchaseRequest $purchaseRequest): void
    {
        $hasExpiredSync = false;

        foreach ($purchaseRequest->supplierRequests as $supplierRequest) {
            if (
                $supplierRequest->status === SupplierRequestStatusEnum::SENT
                && $supplierRequest->public_token_expires_at !== null
                && $supplierRequest->public_token_expires_at->isPast()
            ) {
                $this->syncSupplierRequestExpiry($supplierRequest);
                $hasExpiredSync = true;
            }
        }

        if ($hasExpiredSync) {
            $purchaseRequest->unsetRelation('supplierRequests');
            $purchaseRequest->load('supplierRequests.proposals', 'supplierRequests.proposalDecision');
        }
    }

    /**
     * @param array{
     *     canCreateSupplierRequest?: bool,
     *     canSendSupplierRequest?: bool,
     *     canSubmitProposal?: bool,
     *     canSelectProposal?: bool,
     *     canAcceptProposal?: bool,
     *     canCreateOrder?: bool,
     *     canReceiveMaterials?: bool,
     *     blockers?: array<int, string>,
     *     warnings?: array<int, string>
     * } $options
     */
    private function summary(string $stage, ?string $nextAction, array $options = []): ProcurementLifecycleSummary
    {
        return new ProcurementLifecycleSummary(
            stage: $stage,
            stageLabel: trans_message("procurement.lifecycle.stages.{$stage}"),
            nextAction: $nextAction,
            nextActionLabel: $nextAction === null ? null : trans_message("procurement.lifecycle.actions.{$nextAction}"),
            canCreateSupplierRequest: (bool) ($options['canCreateSupplierRequest'] ?? false),
            canSendSupplierRequest: (bool) ($options['canSendSupplierRequest'] ?? false),
            canSubmitProposal: (bool) ($options['canSubmitProposal'] ?? false),
            canSelectProposal: (bool) ($options['canSelectProposal'] ?? false),
            canAcceptProposal: (bool) ($options['canAcceptProposal'] ?? false),
            canCreateOrder: (bool) ($options['canCreateOrder'] ?? false),
            canReceiveMaterials: (bool) ($options['canReceiveMaterials'] ?? false),
            blockers: $options['blockers'] ?? [],
            warnings: $options['warnings'] ?? [],
        );
    }

    private function blocker(string $key): string
    {
        return trans_message("procurement.lifecycle.blockers.{$key}");
    }

    /**
     * @return array<int, float>
     */
    private function receivedQuantitiesByItemId(PurchaseOrder $order): array
    {
        return DB::table('purchase_receipt_lines')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_lines.purchase_receipt_id')
            ->where('purchase_receipts.purchase_order_id', $order->id)
            ->where('purchase_receipts.status', 'posted')
            ->whereNull('purchase_receipts.deleted_at')
            ->groupBy('purchase_receipt_lines.purchase_order_item_id')
            ->selectRaw('purchase_receipt_lines.purchase_order_item_id, SUM(purchase_receipt_lines.quantity_received) as received_quantity')
            ->pluck('received_quantity', 'purchase_receipt_lines.purchase_order_item_id')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }
}
