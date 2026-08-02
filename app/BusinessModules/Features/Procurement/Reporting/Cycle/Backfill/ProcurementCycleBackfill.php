<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementProcessEventRecorder;
use App\Support\Reporting\OwnerBackfillBatch;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ProcurementCycleBackfill
{
    private const MAX_SLICE = 500;

    private const EVENT_RANK = [
        'request_created' => 0,
        'solicitation_sent' => 2,
        'supplier_responded' => 3,
        'award_decided' => 4,
        'order_sent' => 5,
        'first_receipt' => 6,
        'fully_received' => 7,
    ];

    public function __construct(private ProcurementProcessEventRecorder $events) {}

    public function backfillSlice(int $organizationId, int $cursor, int $limit = self::MAX_SLICE): OwnerBackfillBatch
    {
        $limit = min(self::MAX_SLICE, max(1, $limit));
        $lines = PurchaseRequestLine::query()
            ->with('purchaseRequest.siteRequest')
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
            ->where('purchase_requests.organization_id', $organizationId)
            ->where('purchase_request_lines.id', '>', $cursor)
            ->select('purchase_request_lines.*')
            ->orderBy('purchase_request_lines.id')
            ->limit($limit)
            ->get();
        $lineIds = $lines->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $facts = $this->explicitFacts($organizationId, $lineIds);
        $input = [];
        $projectedIds = [];
        $gaps = 0;
        foreach ($lines as $line) {
            $request = $line->purchaseRequest;
            $lineMetadata = is_array($line->metadata) ? $line->metadata : [];
            $capturedOwner = is_array($lineMetadata['reporting_owner_dimensions'] ?? null)
                ? $lineMetadata['reporting_owner_dimensions']
                : [];
            if ($capturedOwner === []) {
                $gaps++;
            }
            $this->events->captureOwnerExpectation(
                $organizationId,
                (int) $request->id,
                (int) $line->id,
                $request->created_at !== null
                    ? CarbonImmutable::instance($request->created_at)
                    : CarbonImmutable::instance($line->created_at),
                [
                    'project_id' => $capturedOwner['project_id'] ?? null,
                    'requester_id' => $capturedOwner['requester_id'] ?? null,
                    'buyer_id' => $capturedOwner['buyer_id'] ?? null,
                    'material_id' => $capturedOwner['material_id'] ?? null,
                    'amount' => $capturedOwner['amount'] ?? null,
                    'currency' => $capturedOwner['currency'] ?? null,
                    'priority' => $capturedOwner['priority'] ?? null,
                    'dimension_status' => $capturedOwner === [] ? 'unknown' : 'captured',
                ],
            );
            if ($request->created_at === null) {
                $gaps++;

                continue;
            }
            $lineFacts = array_merge([[
                'event_code' => 'request_created',
                'stage' => 'request',
                'occurred_at' => CarbonImmutable::instance($request->created_at),
                'source_event_id' => 'purchase_request_line:'.$line->id.':created',
                'actor_id' => null,
                'supplier_request_id' => null,
                'supplier_proposal_version_id' => null,
                'purchase_order_id' => null,
                'purchase_receipt_id' => null,
            ]], $facts[(int) $line->id] ?? []);
            usort($lineFacts, static function (array $left, array $right): int {
                $timestamp = $left['occurred_at'] <=> $right['occurred_at'];

                return $timestamp !== 0
                    ? $timestamp
                    : self::EVENT_RANK[$left['event_code']] <=> self::EVENT_RANK[$right['event_code']];
            });
            $lastRank = -1;
            foreach ($lineFacts as $fact) {
                $rank = self::EVENT_RANK[$fact['event_code']];
                if ($rank <= $lastRank) {
                    $gaps++;

                    continue;
                }
                $lastRank = $rank;
                $input[] = [
                    'line_id' => (int) $line->id,
                    'event_code' => $fact['event_code'],
                    'occurred_at' => $fact['occurred_at']->format(DATE_ATOM),
                    'source_event_id' => $fact['source_event_id'],
                ];
                try {
                    $existing = ProcurementProcessEvent::query()
                        ->where('organization_id', $organizationId)
                        ->where('purchase_request_line_id', $line->id)
                        ->where('event_code', $fact['event_code'])
                        ->first();
                    $event = $existing instanceof ProcurementProcessEvent
                        ? $existing
                        : $this->events->record(
                            $organizationId,
                            (int) $request->id,
                            (int) $line->id,
                            $fact['event_code'],
                            $fact['stage'],
                            $fact['occurred_at'],
                            1,
                            $fact['source_event_id'],
                            $fact['actor_id'],
                            $fact['supplier_request_id'],
                            $fact['supplier_proposal_version_id'],
                            $fact['purchase_order_id'],
                            $fact['purchase_receipt_id'],
                            ['backfill' => true],
                            $request->siteRequest?->project_id,
                        );
                    $projectedIds[] = (int) $event->id;
                } catch (Throwable) {
                    $gaps++;
                }
            }
        }
        $nextCursor = $lines->isEmpty() ? $cursor : (int) $lines->last()->id;
        $done = $lines->count() < $limit;
        $outputHashes = ProcurementProcessEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projectedIds)
            ->orderBy('id')
            ->pluck('source_hash')
            ->all();

        return new OwnerBackfillBatch(
            $lines->count(),
            count($projectedIds),
            $gaps,
            $nextCursor,
            $done,
            hash('sha256', CanonicalJson::encode($input)),
            hash('sha256', CanonicalJson::encode($outputHashes)),
        );
    }

    private function explicitFacts(int $organizationId, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $facts = [];
        $requestLineBySupplierLine = [];
        $supplierLines = DB::table('supplier_request_lines')
            ->whereIn('purchase_request_line_id', $lineIds)
            ->get(['id', 'purchase_request_line_id']);
        foreach ($supplierLines as $supplierLine) {
            $requestLineBySupplierLine[(int) $supplierLine->id] = (int) $supplierLine->purchase_request_line_id;
        }
        $supplierLineIds = array_keys($requestLineBySupplierLine);
        $encodedLineIds = array_map('strval', $lineIds);
        $encodedSupplierLineIds = array_map('strval', $supplierLineIds);
        $solicitations = DB::table('supplier_request_lines as line')
            ->join('supplier_requests as request', 'request.id', '=', 'line.supplier_request_id')
            ->where('request.organization_id', $organizationId)
            ->whereIn('line.purchase_request_line_id', $lineIds)
            ->whereNotNull('request.sent_at')
            ->whereNull('request.deleted_at')
            ->orderBy('request.sent_at')
            ->orderBy('request.id')
            ->get([
                'line.purchase_request_line_id',
                'request.id',
                'request.sent_at',
            ]);
        $seen = [];
        foreach ($solicitations as $solicitation) {
            $lineId = (int) $solicitation->purchase_request_line_id;
            if (isset($seen[$lineId])) {
                continue;
            }
            $seen[$lineId] = true;
            $facts[$lineId][] = $this->fact(
                'solicitation_sent',
                'solicitation',
                (string) $solicitation->sent_at,
                'supplier_request:'.$solicitation->id.':sent',
                supplierRequestId: (int) $solicitation->id,
            );
        }
        $responses = DB::table('supplier_proposal_lines as proposal_line')
            ->join('supplier_request_lines as request_line', 'request_line.id', '=', 'proposal_line.supplier_request_line_id')
            ->join('supplier_proposals as proposal', 'proposal.id', '=', 'proposal_line.supplier_proposal_id')
            ->join('supplier_proposal_versions as version', function ($join): void {
                $join->on('version.supplier_proposal_id', '=', 'proposal.id')
                    ->where('version.version_number', 1);
            })
            ->where('proposal.organization_id', $organizationId)
            ->whereIn('request_line.purchase_request_line_id', $lineIds)
            ->whereNull('proposal.deleted_at')
            ->orderBy('proposal.created_at')
            ->orderBy('proposal.id')
            ->get([
                'request_line.purchase_request_line_id',
                'proposal.supplier_request_id',
                'proposal.created_at',
                'version.id as version_id',
            ]);
        $seen = [];
        foreach ($responses as $response) {
            $lineId = (int) $response->purchase_request_line_id;
            if (isset($seen[$lineId])) {
                continue;
            }
            $seen[$lineId] = true;
            $facts[$lineId][] = $this->fact(
                'supplier_responded',
                'solicitation',
                (string) $response->created_at,
                'supplier_proposal_version:'.$response->version_id.':submitted',
                supplierRequestId: (int) $response->supplier_request_id,
                supplierProposalVersionId: (int) $response->version_id,
            );
        }
        $decisions = DB::table('supplier_proposal_decisions as decision')
            ->join(
                'supplier_proposal_lines as proposal_line',
                'proposal_line.supplier_proposal_id',
                '=',
                'decision.winning_supplier_proposal_id',
            )
            ->join('supplier_request_lines as request_line', 'request_line.id', '=', 'proposal_line.supplier_request_line_id')
            ->where('decision.organization_id', $organizationId)
            ->whereIn('request_line.purchase_request_line_id', $lineIds)
            ->whereNotNull('decision.selected_at')
            ->whereNotNull('decision.winning_supplier_proposal_version_id')
            ->orderBy('decision.selected_at')
            ->orderBy('decision.id')
            ->get([
                'request_line.purchase_request_line_id',
                'decision.id',
                'decision.supplier_request_id',
                'decision.winning_supplier_proposal_version_id',
                'decision.selected_by',
                'decision.selected_at',
            ]);
        $seen = [];
        foreach ($decisions as $decision) {
            $lineId = (int) $decision->purchase_request_line_id;
            if (isset($seen[$lineId])) {
                continue;
            }
            $seen[$lineId] = true;
            $facts[$lineId][] = $this->fact(
                'award_decided',
                'award',
                (string) $decision->selected_at,
                'supplier_award_decision:'.$decision->id.':selected',
                $decision->selected_by === null ? null : (int) $decision->selected_by,
                (int) $decision->supplier_request_id,
                (int) $decision->winning_supplier_proposal_version_id,
            );
        }
        $orderItems = DB::table('purchase_order_items as item')
            ->join('purchase_orders as purchase_order', 'purchase_order.id', '=', 'item.purchase_order_id')
            ->where('purchase_order.organization_id', $organizationId)
            ->where(function ($query) use ($encodedLineIds, $encodedSupplierLineIds): void {
                $query->whereIn(DB::raw("item.metadata->>'purchase_request_line_id'"), $encodedLineIds);
                if ($encodedSupplierLineIds !== []) {
                    $query->orWhereIn(
                        DB::raw("item.metadata->>'supplier_request_line_id'"),
                        $encodedSupplierLineIds,
                    );
                }
            })
            ->whereNotNull('purchase_order.sent_at')
            ->whereNull('purchase_order.deleted_at')
            ->orderBy('purchase_order.sent_at')
            ->orderBy('item.id')
            ->get([
                'item.id',
                'item.purchase_order_id',
                'item.quantity',
                'item.metadata',
                'purchase_order.sent_at',
            ]);
        $requestLineByOrderItem = [];
        $orderedByLine = [];
        $seen = [];
        foreach ($orderItems as $item) {
            $lineId = $this->requestLineId((string) $item->metadata, $requestLineBySupplierLine);
            if ($lineId === null || ! in_array($lineId, $lineIds, true)) {
                continue;
            }
            $requestLineByOrderItem[(int) $item->id] = $lineId;
            $orderedByLine[$lineId] = ($orderedByLine[$lineId] ?? BigDecimal::zero())
                ->plus((string) $item->quantity);
            if (isset($seen[$lineId])) {
                continue;
            }
            $seen[$lineId] = true;
            $facts[$lineId][] = $this->fact(
                'order_sent',
                'order',
                (string) $item->sent_at,
                'purchase_order_item:'.$item->id.':sent',
                purchaseOrderId: (int) $item->purchase_order_id,
            );
        }
        $receiptLines = DB::table('purchase_receipt_lines as line')
            ->join('purchase_receipts as receipt', 'receipt.id', '=', 'line.purchase_receipt_id')
            ->join('purchase_order_items as item', 'item.id', '=', 'line.purchase_order_item_id')
            ->join('purchase_orders as purchase_order', 'purchase_order.id', '=', 'item.purchase_order_id')
            ->where('purchase_order.organization_id', $organizationId)
            ->where(function ($query) use ($encodedLineIds, $encodedSupplierLineIds): void {
                $query->whereIn(DB::raw("item.metadata->>'purchase_request_line_id'"), $encodedLineIds);
                if ($encodedSupplierLineIds !== []) {
                    $query->orWhereIn(
                        DB::raw("item.metadata->>'supplier_request_line_id'"),
                        $encodedSupplierLineIds,
                    );
                }
            })
            ->where('receipt.status', 'posted')
            ->whereNull('receipt.deleted_at')
            ->whereNull('purchase_order.deleted_at')
            ->whereNotNull(DB::raw("line.metadata->>'reporting_posted_at'"))
            ->orderByRaw("line.metadata->>'reporting_posted_at'")
            ->orderBy('line.id')
            ->get([
                'line.id',
                'line.purchase_receipt_id',
                'line.purchase_order_item_id',
                'line.quantity_received',
                'line.metadata as receipt_line_metadata',
                'item.purchase_order_id',
                'item.quantity as ordered_quantity',
                'item.metadata',
                'receipt.received_by_user_id',
            ]);
        $receivedByItem = [];
        $firstReceiptByLine = [];
        $fullReceiptByLine = [];
        foreach ($receiptLines as $receiptLine) {
            $itemId = (int) $receiptLine->purchase_order_item_id;
            $lineId = $requestLineByOrderItem[$itemId]
                ?? $this->requestLineId((string) $receiptLine->metadata, $requestLineBySupplierLine);
            if ($lineId === null || ! in_array($lineId, $lineIds, true)) {
                continue;
            }
            $received = ($receivedByItem[$itemId] ?? BigDecimal::zero())
                ->plus((string) $receiptLine->quantity_received);
            $receivedByItem[$itemId] = $received;
            $receiptMetadata = json_decode((string) $receiptLine->receipt_line_metadata, true);
            $postedAt = is_array($receiptMetadata)
                ? ($receiptMetadata['reporting_posted_at'] ?? null)
                : null;
            if (! is_string($postedAt) || trim($postedAt) === '') {
                continue;
            }
            if (! isset($firstReceiptByLine[$lineId])) {
                $firstReceiptByLine[$lineId] = true;
                $facts[$lineId][] = $this->fact(
                    'first_receipt',
                    'receipt',
                    CarbonImmutable::parse($postedAt)->toIso8601String(),
                    'purchase_receipt_line:'.$receiptLine->id.':first_receipt',
                    $receiptLine->received_by_user_id === null ? null : (int) $receiptLine->received_by_user_id,
                    purchaseOrderId: (int) $receiptLine->purchase_order_id,
                    purchaseReceiptId: (int) $receiptLine->purchase_receipt_id,
                );
            }
            $lineReceived = collect($requestLineByOrderItem)
                ->filter(static fn (int $ownerLineId): bool => $ownerLineId === $lineId)
                ->keys()
                ->reduce(
                    static fn (BigDecimal $total, int $ownerItemId): BigDecimal => $total
                        ->plus((string) ($receivedByItem[$ownerItemId] ?? '0')),
                    BigDecimal::zero(),
                );
            if (! isset($fullReceiptByLine[$lineId])
                && isset($orderedByLine[$lineId])
                && $lineReceived->isGreaterThanOrEqualTo($orderedByLine[$lineId])) {
                $fullReceiptByLine[$lineId] = true;
                $facts[$lineId][] = $this->fact(
                    'fully_received',
                    'receipt',
                    CarbonImmutable::parse($postedAt)->toIso8601String(),
                    'purchase_receipt_line:'.$receiptLine->id.':fully_received',
                    $receiptLine->received_by_user_id === null ? null : (int) $receiptLine->received_by_user_id,
                    purchaseOrderId: (int) $receiptLine->purchase_order_id,
                    purchaseReceiptId: (int) $receiptLine->purchase_receipt_id,
                );
            }
        }

        return $facts;
    }

    private function fact(
        string $eventCode,
        string $stage,
        string $occurredAt,
        string $sourceEventId,
        ?int $actorId = null,
        ?int $supplierRequestId = null,
        ?int $supplierProposalVersionId = null,
        ?int $purchaseOrderId = null,
        ?int $purchaseReceiptId = null,
    ): array {
        return [
            'event_code' => $eventCode,
            'stage' => $stage,
            'occurred_at' => CarbonImmutable::parse($occurredAt),
            'source_event_id' => $sourceEventId,
            'actor_id' => $actorId,
            'supplier_request_id' => $supplierRequestId,
            'supplier_proposal_version_id' => $supplierProposalVersionId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_receipt_id' => $purchaseReceiptId,
        ];
    }

    private function requestLineId(string $encodedMetadata, array $requestLineBySupplierLine): ?int
    {
        $metadata = json_decode($encodedMetadata, true);
        if (! is_array($metadata)) {
            return null;
        }
        if (isset($metadata['purchase_request_line_id'])) {
            return (int) $metadata['purchase_request_line_id'];
        }
        if (isset($metadata['supplier_request_line_id'])) {
            return $requestLineBySupplierLine[(int) $metadata['supplier_request_line_id']] ?? null;
        }

        return null;
    }
}
