<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityBackfillWatermark;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\PurchaseOrderPromiseVersionRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SentPurchaseOrderLineOwnerRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder;
use App\Support\Reporting\OwnerBackfillBatch;
use Carbon\CarbonImmutable;
use DomainException;
use Throwable;
use Illuminate\Support\Facades\DB;

final readonly class SupplyReliabilityBackfill
{
    private const MAX_SLICE = 500;

    public function __construct(
        private PurchaseOrderPromiseVersionRecorder $promises,
        private SentPurchaseOrderLineOwnerRecorder $sentOwners,
        private SupplyLifecycleEventRecorder $events,
    ) {}

    public function backfillSlice(int $organizationId, int $cursor, int $limit = self::MAX_SLICE): OwnerBackfillBatch
    {
        $limit = min(self::MAX_SLICE, max(1, $limit));
        $watermark = $this->watermark($organizationId);
        $items = PurchaseOrderItem::query()
            ->with(['purchaseOrder.purchaseRequest.siteRequest', 'receiptLines.purchaseReceipt'])
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.organization_id', $organizationId)
            ->whereNotNull('purchase_orders.sent_at')
            ->where('purchase_order_items.id', '>', $cursor)
            ->where('purchase_order_items.id', '<=', $watermark->target_item_id)
            ->select('purchase_order_items.*')
            ->orderBy('purchase_order_items.id')
            ->limit($limit)
            ->get();
        $input = [];
        $projected = [];
        $projectedOwnerIds = [];
        $gaps = 0;
        foreach ($items as $item) {
            $order = $item->purchaseOrder;
            $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $promiseEvidence = $metadata['reporting_original_promised_at']
                ?? $orderMetadata['delivery_date_at_first_send']
                ?? null;
            $sentEvidence = $orderMetadata['reporting_sent_at'] ?? null;
            $confirmedEvidence = $orderMetadata['reporting_confirmed_at'] ?? null;
            $cancelledEvidence = $orderMetadata['reporting_cancelled_at'] ?? null;
            $unitDimension = $metadata['reporting_unit_dimension'] ?? null;
            $unitCode = $metadata['reporting_unit_code'] ?? null;
            $conversionVersion = $metadata['reporting_conversion_version'] ?? null;
            $capturedOwner = $metadata['reporting_sent_owner_dimensions'] ?? null;
            $input[] = [
                'item_id' => (int) $item->id,
                'sent_at' => $order->sent_at?->format(DATE_ATOM),
                'sent_evidence' => $sentEvidence,
                'confirmed_evidence' => $confirmedEvidence,
                'cancelled_evidence' => $cancelledEvidence,
                'promise_evidence' => $promiseEvidence,
            ];
            if (! is_string($promiseEvidence)
                || trim($promiseEvidence) === ''
                || ! is_string($sentEvidence)
                || trim($sentEvidence) === ''
                || ! is_string($unitDimension)
                || trim($unitDimension) === ''
                || ! is_string($unitCode)
                || trim($unitCode) === ''
                || ! is_string($conversionVersion)
                || trim($conversionVersion) === ''
                || ! is_array($capturedOwner)
                || ! is_string($orderMetadata['tax_basis'] ?? null)
                || ! is_string($orderMetadata['freight_basis'] ?? null)) {
                $gaps++;

                continue;
            }
            try {
                $sentAt = CarbonImmutable::parse($sentEvidence)->utc();
                $confirmedAt = is_string($confirmedEvidence) && trim($confirmedEvidence) !== ''
                    ? CarbonImmutable::parse($confirmedEvidence)->utc()
                    : null;
                $cancelledAt = is_string($cancelledEvidence) && trim($cancelledEvidence) !== ''
                    ? CarbonImmutable::parse($cancelledEvidence)->utc()
                    : null;
                if ($order->status === PurchaseOrderStatusEnum::CANCELLED && $cancelledAt === null) {
                    throw new DomainException('Legacy cancelled order requires explicit cancellation evidence.');
                }
                if ($order->confirmed_at !== null && $confirmedAt === null) {
                    throw new DomainException('Legacy confirmed order requires explicit confirmation evidence.');
                }
                if (($confirmedAt !== null && $confirmedAt->lessThan($sentAt))
                    || ($cancelledAt !== null && $cancelledAt->lessThan($sentAt))) {
                    throw new DomainException('Legacy lifecycle evidence is not chronologically valid.');
                }
                $evidenceHash = hash('sha256', CanonicalJson::encode([
                    'order_id' => (int) $order->id,
                    'sent_at' => $sentEvidence,
                    'confirmed_at' => $confirmedEvidence,
                    'cancelled_at' => $cancelledEvidence,
                ]));
                $owner = $this->sentOwners->recordBackfill(
                    $item,
                    $sentAt,
                    $capturedOwner + [
                        'source_version' => 1,
                        'unit_dimension' => $unitDimension,
                        'unit_code' => $unitCode,
                        'conversion_version' => $conversionVersion,
                    ],
                );
                $projectedOwnerIds[] = (int) $owner->id;
                $basis = array_merge([
                    'reporting_source_version' => 1,
                    'unit_dimension' => $unitDimension,
                    'unit_code' => $unitCode,
                    'unit_conversion_version' => $conversionVersion,
                    'tax_basis' => $orderMetadata['tax_basis'],
                    'freight_basis' => $orderMetadata['freight_basis'],
                ], $metadata);
                $promise = $this->promises->captureBackfillOriginal(
                    $item->fresh(),
                    CarbonImmutable::parse($promiseEvidence),
                    $sentAt,
                    $basis,
                );
                $sent = $this->events->record(
                    $promise,
                    'sent',
                    'purchase_order',
                    (int) $order->id,
                    1,
                    '0',
                    $sentAt,
                    'purchase_order_item:'.$item->id.':sent',
                    evidence: [
                        'backfill' => true,
                        'owner_timestamp_evidence_hash' => $evidenceHash,
                        'owner_timestamp' => $sentAt->format(DATE_ATOM),
                    ],
                );
                $projected[] = (int) $sent->id;
                if ($confirmedAt !== null) {
                    $confirmed = $this->events->record(
                        $promise,
                        'confirmed',
                        'purchase_order',
                        (int) $order->id,
                        1,
                        '0',
                        $confirmedAt,
                        'purchase_order_item:'.$item->id.':confirmed',
                        evidence: [
                            'backfill' => true,
                            'owner_timestamp_evidence_hash' => $evidenceHash,
                            'owner_timestamp' => $confirmedAt->format(DATE_ATOM),
                        ],
                    );
                    $projected[] = (int) $confirmed->id;
                }
                if ($cancelledAt !== null) {
                    $cancelled = $this->events->record(
                        $promise,
                        'cancelled',
                        'purchase_order',
                        (int) $order->id,
                        1,
                        '0',
                        $cancelledAt,
                        'purchase_order_item:'.$item->id.':cancelled',
                        evidence: [
                            'backfill' => true,
                            'remediated_owner_timestamp' => true,
                            'owner_timestamp_evidence_hash' => $evidenceHash,
                            'owner_timestamp' => $cancelledAt->format(DATE_ATOM),
                        ],
                    );
                    $projected[] = (int) $cancelled->id;
                }
                foreach ($item->receiptLines->sortBy('id') as $line) {
                    $lineMetadata = is_array($line->metadata) ? $line->metadata : [];
                    $postedAt = $lineMetadata['reporting_posted_at'] ?? null;
                    $sourceVersion = $lineMetadata['reporting_source_version'] ?? null;
                    if (! is_string($postedAt)
                        || trim($postedAt) === ''
                        || ! is_int($sourceVersion)
                        || $sourceVersion < 1) {
                        $gaps++;

                        continue;
                    }
                    $receipt = $this->events->receiptBackfill(
                        $line,
                        CarbonImmutable::parse($postedAt),
                        $sourceVersion,
                    );
                    $projected[] = (int) $receipt->id;
                    $corrections = $lineMetadata['reporting_return_events'] ?? [];
                    if (! is_array($corrections)) {
                        $gaps++;

                        continue;
                    }
                    foreach ($corrections as $correction) {
                        if (! is_array($correction)
                            || ! in_array($correction['event_type'] ?? null, ['receipt_reversed', 'returned'], true)
                            || ! is_string($correction['occurred_at'] ?? null)
                            || ! is_string($correction['quantity'] ?? null)
                            || ! is_string($correction['source_type'] ?? null)
                            || ! is_int($correction['source_id'] ?? null)
                            || ! is_int($correction['source_version'] ?? null)) {
                            $gaps++;

                            continue;
                        }
                        $eventType = $correction['event_type'];
                        $occurredAt = CarbonImmutable::parse($correction['occurred_at']);
                        $signedQuantity = '-'.ltrim($correction['quantity'], '+-');
                        $existingCorrection = SupplyLifecycleEvent::query()
                            ->where('organization_id', $organizationId)
                            ->where('source_type', $correction['source_type'])
                            ->where('source_id', $correction['source_id'])
                            ->where('source_version', $correction['source_version'])
                            ->where('event_type', $eventType)
                            ->first();
                        if ($existingCorrection instanceof SupplyLifecycleEvent) {
                            if ((string) $existingCorrection->signed_quantity !== $signedQuantity
                                || ! $existingCorrection->occurred_at->equalTo($occurredAt)) {
                                throw new DomainException('Supply correction source identity conflicts with history.');
                            }
                            $projected[] = (int) $existingCorrection->id;

                            continue;
                        }
                        $correctionEvent = $this->events->record(
                            $promise,
                            $eventType,
                            $correction['source_type'],
                            $correction['source_id'],
                            $correction['source_version'],
                            $signedQuantity,
                            $occurredAt,
                            'supply-correction:'.$correction['source_type'].':'
                                .$correction['source_id'].':'.$correction['source_version'].':'.$eventType,
                            is_string($correction['reason_code'] ?? null) ? $correction['reason_code'] : null,
                            $eventType === 'receipt_reversed' ? (int) $receipt->id : null,
                            ['backfill' => true, 'purchase_receipt_line_id' => (int) $line->id],
                        );
                        $projected[] = (int) $correctionEvent->id;
                    }
                }
            } catch (Throwable) {
                $gaps++;
            }
        }
        $nextCursor = $items->isEmpty() ? $cursor : (int) $items->last()->id;
        $done = $nextCursor >= (int) $watermark->target_item_id || $items->count() < $limit;
        $watermark->forceFill([
            'completed_item_id' => max((int) $watermark->completed_item_id, $nextCursor),
            'completed_at' => $done ? now() : null,
        ])->save();
        $output = SupplyLifecycleEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projected)
            ->orderBy('id')
            ->pluck('source_hash')
            ->all();
        $output = [
            ...SentPurchaseOrderLineOwner::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $projectedOwnerIds)
                ->orderBy('id')
                ->pluck('source_hash')
                ->all(),
            ...$output,
        ];

        return new OwnerBackfillBatch(
            $items->count(),
            count($projected) + count($projectedOwnerIds),
            $gaps,
            $nextCursor,
            $done,
            hash('sha256', CanonicalJson::encode($input)),
            hash('sha256', CanonicalJson::encode($output)),
        );
    }

    private function watermark(int $organizationId): SupplyReliabilityBackfillWatermark
    {
        return DB::transaction(function () use ($organizationId): SupplyReliabilityBackfillWatermark {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [$organizationId, 17]);
            }
            $existing = SupplyReliabilityBackfillWatermark::query()->find($organizationId);
            if ($existing instanceof SupplyReliabilityBackfillWatermark) {
                return $existing;
            }
            $target = PurchaseOrderItem::query()
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                ->where('purchase_orders.organization_id', $organizationId)
                ->whereNotNull('purchase_orders.sent_at')
                ->selectRaw('COALESCE(MAX(purchase_order_items.id), 0) AS target_item_id')
                ->selectRaw('MAX(purchase_orders.sent_at) AS target_sent_at')
                ->first();

            return SupplyReliabilityBackfillWatermark::query()->create([
                'organization_id' => $organizationId,
                'target_item_id' => (int) ($target?->target_item_id ?? 0),
                'completed_item_id' => 0,
                'target_sent_at' => $target?->target_sent_at,
                'completed_at' => null,
            ]);
        }, 3);
    }
}
