<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestLine;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementProcessEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\PurchaseOrderPromiseVersionRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SentPurchaseOrderLineOwnerRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Support\OrganizationBusinessTimezone;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final readonly class ProcurementReportingLifecycleRecorder
{
    public function __construct(
        private ProcurementProcessEventRecorder $processEvents,
        private PurchaseOrderPromiseVersionRecorder $promiseVersions,
        private SentPurchaseOrderLineOwnerRecorder $sentLineOwners,
        private SupplyLifecycleEventRecorder $supplyEvents,
        private OrganizationBusinessTimezone $businessTimezone,
    ) {}

    public function requestCreated(PurchaseRequest $request, ?int $actorId): void
    {
        $request->loadMissing(['lines', 'siteRequest']);
        foreach ($request->lines as $line) {
            $this->processEvents->record(
                (int) $request->organization_id,
                (int) $request->id,
                (int) $line->id,
                'request_created',
                'request',
                $request->created_at,
                1,
                'purchase_request_line:'.$line->id.':created',
                $actorId,
                projectId: $request->siteRequest?->project_id,
            );
        }
    }

    public function requestApproved(PurchaseRequest $request, int $actorId): void
    {
        $request->loadMissing(['lines', 'siteRequest']);
        foreach ($request->lines as $line) {
            $this->processEvents->record(
                (int) $request->organization_id,
                (int) $request->id,
                (int) $line->id,
                'request_approved',
                'approval',
                CarbonImmutable::now('UTC'),
                1,
                'purchase_request:'.$request->id.':approved',
                $actorId,
                projectId: $request->siteRequest?->project_id,
            );
        }
    }

    public function requestCancelled(PurchaseRequest $request, int $actorId): void
    {
        $request->loadMissing(['lines', 'siteRequest']);
        foreach ($request->lines as $line) {
            $this->processEvents->record(
                (int) $request->organization_id,
                (int) $request->id,
                (int) $line->id,
                'cancelled',
                'cancelled',
                CarbonImmutable::now('UTC'),
                1,
                'purchase_request:'.$request->id.':cancelled',
                $actorId,
                projectId: $request->siteRequest?->project_id,
            );
        }
    }

    public function solicitationSent(SupplierRequest $request, ?int $actorId): void
    {
        $request->loadMissing(['lines.purchaseRequestLine.purchaseRequest.siteRequest']);
        foreach ($request->lines as $line) {
            $purchaseLine = $line->purchaseRequestLine;
            if ($purchaseLine === null) {
                throw new DomainException('Supplier request line must reference a purchase request line.');
            }
            if ($this->hasProcessEvent((int) $request->organization_id, (int) $purchaseLine->id, 'solicitation_sent')) {
                continue;
            }
            $purchaseRequest = $purchaseLine->purchaseRequest;
            $this->processEvents->record(
                (int) $request->organization_id,
                (int) $purchaseRequest->id,
                (int) $purchaseLine->id,
                'solicitation_sent',
                'solicitation',
                $request->sent_at,
                1,
                'supplier_request:'.$request->id.':sent',
                $actorId,
                supplierRequestId: (int) $request->id,
                projectId: $purchaseRequest->siteRequest?->project_id,
            );
        }
    }

    public function supplierResponded(SupplierProposal $proposal, ?int $actorId): void
    {
        $proposal->loadMissing([
            'currentVersion',
            'lines.supplierRequestLine.purchaseRequestLine.purchaseRequest.siteRequest',
        ]);
        if ($proposal->currentVersion === null) {
            throw new DomainException('Supplier proposal version is required for reporting.');
        }
        foreach ($proposal->lines as $line) {
            $requestLine = $line->supplierRequestLine?->purchaseRequestLine;
            if ($requestLine === null) {
                throw new DomainException('Supplier proposal line must reference a purchase request line.');
            }
            if ($this->hasProcessEvent((int) $proposal->organization_id, (int) $requestLine->id, 'supplier_responded')) {
                continue;
            }
            $request = $requestLine->purchaseRequest;
            $this->processEvents->record(
                (int) $proposal->organization_id,
                (int) $request->id,
                (int) $requestLine->id,
                'supplier_responded',
                'solicitation',
                $proposal->created_at,
                1,
                'supplier_proposal_version:'.$proposal->currentVersion->id.':submitted',
                $actorId,
                supplierRequestId: (int) $proposal->supplier_request_id,
                supplierProposalVersionId: (int) $proposal->currentVersion->id,
                projectId: $request->siteRequest?->project_id,
            );
        }
    }

    public function awardDecided(SupplierProposalDecision $decision): void
    {
        $decision->loadMissing([
            'winningProposal.currentVersion',
            'winningProposal.lines.supplierRequestLine.purchaseRequestLine.purchaseRequest.siteRequest',
        ]);
        $proposal = $decision->winningProposal;
        $version = $proposal?->currentVersion;
        if ($proposal === null || $version === null) {
            throw new DomainException('Award decision must pin a supplier proposal version.');
        }
        foreach ($proposal->lines as $line) {
            $requestLine = $line->supplierRequestLine?->purchaseRequestLine;
            if ($requestLine === null) {
                throw new DomainException('Award proposal line must reference a purchase request line.');
            }
            if ($this->hasProcessEvent((int) $decision->organization_id, (int) $requestLine->id, 'award_decided')) {
                continue;
            }
            $request = $requestLine->purchaseRequest;
            $this->processEvents->record(
                (int) $decision->organization_id,
                (int) $request->id,
                (int) $requestLine->id,
                'award_decided',
                'award',
                $decision->selected_at,
                1,
                'supplier_award_decision:'.$decision->id.':selected',
                $decision->selected_by,
                supplierRequestId: (int) $decision->supplier_request_id,
                supplierProposalVersionId: (int) $version->id,
                projectId: $request->siteRequest?->project_id,
            );
        }
    }

    public function orderSent(PurchaseOrder $order, ?int $actorId): void
    {
        $order->loadMissing(['items', 'purchaseRequest.lines', 'purchaseRequest.siteRequest']);
        foreach ($order->items as $item) {
            $this->sentLineOwners->record($item, CarbonImmutable::instance($order->sent_at));
            $promise = $this->originalPromise($order, $item);
            $this->supplyEvents->record(
                $promise,
                'sent',
                'purchase_order',
                (int) $order->id,
                1,
                '0',
                CarbonImmutable::instance($order->sent_at),
                'purchase_order_item:'.$item->id.':sent',
                evidence: ['purchase_order_id' => (int) $order->id],
            );
            $requestLineId = $this->purchaseRequestLineId($item);
            $this->processEvents->record(
                (int) $order->organization_id,
                (int) $order->purchase_request_id,
                $requestLineId,
                'order_sent',
                'order',
                $order->sent_at,
                1,
                'purchase_order_item:'.$item->id.':sent',
                $actorId,
                purchaseOrderId: (int) $order->id,
                projectId: $order->purchaseRequest?->siteRequest?->project_id,
            );
        }
    }

    public function prepareOrderPromises(PurchaseOrder $order): void
    {
        $order->loadMissing(['organization', 'items', 'purchaseRequest.lines', 'purchaseRequest.siteRequest']);
        if ($order->delivery_date === null) {
            throw new DomainException(trans_message('procurement.purchase_orders.delivery_date_required'));
        }
        $promisedAt = CarbonImmutable::parse(
            $order->delivery_date->format('Y-m-d'),
            $this->businessTimezone->resolve($order->organization),
        )->endOfDay();
        foreach ($order->items as $item) {
            $this->pinOrderItemBasis($order, $item);
            $this->promiseVersions->captureOriginal($item->fresh(), $promisedAt);
        }
    }

    public function orderConfirmed(PurchaseOrder $order): void
    {
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            $promise = $this->originalPromise($order, $item);
            $this->supplyEvents->record(
                $promise,
                'confirmed',
                'purchase_order',
                (int) $order->id,
                1,
                '0',
                CarbonImmutable::instance($order->confirmed_at),
                'purchase_order_item:'.$item->id.':confirmed',
            );
        }
    }

    public function orderCancelled(PurchaseOrder $order, CarbonImmutable $occurredAt, string $reasonCode): void
    {
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            $promise = $this->originalPromise($order, $item);
            $this->supplyEvents->record(
                $promise,
                'cancelled',
                'purchase_order',
                (int) $order->id,
                1,
                '0',
                $occurredAt,
                'purchase_order_item:'.$item->id.':cancelled',
                reasonCode: $reasonCode,
                evidence: ['purchase_order_id' => (int) $order->id],
            );
        }
    }

    public function receipt(PurchaseReceiptLine $line, int $actorId): void
    {
        $line->loadMissing([
            'purchaseReceipt.purchaseOrder.purchaseRequest.siteRequest',
            'purchaseOrderItem.receiptLines',
        ]);
        $metadata = is_array($line->metadata) ? $line->metadata : [];
        if (($metadata['reporting_source_version'] ?? null) !== 1) {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_history_version_required'));
        }
        $postedAt = $metadata['reporting_posted_at'] ?? null;
        if (! is_string($postedAt) || trim($postedAt) === '') {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_posted_at_required'));
        }
        $occurredAt = CarbonImmutable::parse($postedAt);
        $this->supplyEvents->receipt($line->fresh(), $occurredAt);

        $item = $line->purchaseOrderItem;
        $order = $line->purchaseReceipt->purchaseOrder;
        $requestLineId = $this->purchaseRequestLineId($item);
        $received = BigDecimal::of(
            (string) $item->receiptLines
                ->where('id', '<=', $line->id)
                ->sum('quantity_received'),
        );
        $previouslyReceived = $received->minus((string) $line->quantity_received);
        $eventCodes = [];
        if (! $previouslyReceived->isPositive()) {
            $eventCodes[] = 'first_receipt';
        }
        if ($this->isRequestLineFullyReceivedAsOf(
            (int) $order->organization_id,
            $requestLineId,
            $occurredAt,
        )) {
            $eventCodes[] = 'fully_received';
        }
        foreach ($eventCodes as $eventCode) {
            $this->processEvents->record(
                (int) $order->organization_id,
                (int) $order->purchase_request_id,
                $requestLineId,
                $eventCode,
                'receipt',
                $occurredAt,
                1,
                'purchase_receipt_line:'.$line->id.':'.$eventCode,
                $actorId,
                purchaseOrderId: (int) $order->id,
                purchaseReceiptId: (int) $line->purchase_receipt_id,
                projectId: $order->purchaseRequest?->siteRequest?->project_id,
            );
        }
    }

    public function receiptReversed(
        PurchaseReceiptLine $line,
        string $reasonCode,
        CarbonImmutable $occurredAt,
    ): SupplyLifecycleEvent {
        return $this->supplyEvents->reversal($line, $reasonCode, $occurredAt);
    }

    private function pinOrderItemBasis(PurchaseOrder $order, PurchaseOrderItem $item): void
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $requestLineId = $this->purchaseRequestLineId($item);
        $unitIdentity = 'unit-code:'.hash('sha256', mb_strtolower(trim((string) $item->unit)));
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
        $commercial = is_array($orderMetadata['commercial_snapshot'] ?? null)
            ? $orderMetadata['commercial_snapshot']
            : [];
        $taxBasis = $orderMetadata['tax_basis']
            ?? (isset($commercial['vat_mode'])
                ? (string) $commercial['vat_mode'].':'.(string) ($commercial['vat_rate'] ?? '')
                : null);
        $freightBasis = $orderMetadata['freight_basis']
            ?? (isset($commercial['delivery_amount'])
                ? (string) ($commercial['delivery_terms'] ?? '').':'.(string) $commercial['delivery_amount']
                : null);
        if (! is_string($taxBasis)
            || trim($taxBasis) === ''
            || ! is_string($freightBasis)
            || trim($freightBasis) === '') {
            throw new DomainException(trans_message('procurement.purchase_orders.commercial_basis_required'));
        }
        $metadata = array_merge([
            'purchase_request_line_id' => $requestLineId,
            'reporting_source_version' => 1,
            'unit_dimension' => $unitIdentity,
            'unit_conversion_version' => $unitIdentity.':identity-v1',
            'tax_basis' => $taxBasis,
            'freight_basis' => $freightBasis,
        ], $metadata);
        $item->forceFill(['metadata' => $metadata])->save();
    }

    private function originalPromise(PurchaseOrder $order, PurchaseOrderItem $item): PurchaseOrderPromiseVersion
    {
        $promise = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $order->organization_id)
            ->where('purchase_order_item_id', $item->id)
            ->where('promise_version', 1)
            ->first();
        if (! $promise instanceof PurchaseOrderPromiseVersion) {
            throw new DomainException('Original purchase order promise is required.');
        }

        return $promise;
    }

    private function purchaseRequestLineId(PurchaseOrderItem $item): int
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $lineId = $metadata['purchase_request_line_id'] ?? null;
        if (! is_int($lineId) && isset($metadata['supplier_request_line_id'])) {
            $lineId = SupplierRequestLine::query()
                ->whereKey((int) $metadata['supplier_request_line_id'])
                ->value('purchase_request_line_id');
        }
        if (! is_numeric($lineId) || (int) $lineId < 1) {
            throw new DomainException('Purchase order item must reference a purchase request line.');
        }

        return (int) $lineId;
    }

    private function hasProcessEvent(int $organizationId, int $lineId, string $eventCode): bool
    {
        return ProcurementProcessEvent::query()
            ->where('organization_id', $organizationId)
            ->where('purchase_request_line_id', $lineId)
            ->where('event_code', $eventCode)
            ->exists();
    }

    private function isRequestLineFullyReceivedAsOf(
        int $organizationId,
        int $purchaseRequestLineId,
        CarbonImmutable $asOf,
    ): bool {
        $lineTotals = DB::table('sent_purchase_order_line_owners as sent_owner')
            ->leftJoin('purchase_order_promise_versions as sent_promise', function ($join): void {
                $join->on(
                    'sent_promise.purchase_order_item_id',
                    '=',
                    'sent_owner.purchase_order_item_id',
                )->where('sent_promise.promise_version', 1);
            })
            ->leftJoin('supply_lifecycle_events as sent_event', function ($join) use ($asOf): void {
                $join->on('sent_event.promise_version_id', '=', 'sent_promise.id')
                    ->where('sent_event.occurred_at', '<=', $asOf);
            })
            ->where('sent_owner.organization_id', $organizationId)
            ->where('sent_owner.purchase_request_line_id', $purchaseRequestLineId)
            ->selectRaw(
                'sent_owner.purchase_order_item_id, sent_promise.id AS promise_id, '
                .'sent_promise.ordered_quantity, '
                ."COALESCE(SUM(CASE WHEN sent_event.event_type IN "
                ."('received','receipt_reversed','returned') THEN sent_event.signed_quantity ELSE 0 END), 0) "
                .'AS received_quantity',
            )
            ->groupBy(
                'sent_owner.purchase_order_item_id',
                'sent_promise.id',
                'sent_promise.ordered_quantity',
            );
        $aggregate = DB::query()
            ->fromSub($lineTotals, 'sent_line_totals')
            ->selectRaw(
                'COUNT(*) AS owner_count, COUNT(promise_id) AS promise_count, '
                .'COALESCE(SUM(ordered_quantity), 0) AS ordered_quantity, '
                .'COALESCE(SUM(received_quantity), 0) AS received_quantity',
            )
            ->first();

        return $aggregate !== null
            && (int) $aggregate->owner_count > 0
            && (int) $aggregate->owner_count === (int) $aggregate->promise_count
            && BigDecimal::of((string) $aggregate->received_quantity)
                ->isGreaterThanOrEqualTo((string) $aggregate->ordered_quantity);
    }
}
