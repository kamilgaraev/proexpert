<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use DateTimeInterface;
use Illuminate\Support\Collection;

final readonly class SupplyBackfillEvidenceHasher
{
    private const RELATIONS = [
        'purchaseOrder.purchaseRequest.siteRequest',
        'receiptLines.purchaseReceipt',
        'receiptLines.returns',
    ];

    public function inputSlice(
        ?string $previousHash,
        Collection $items,
        DateTimeInterface $historicalCutoff,
    ): string {
        $hash = $previousHash;
        foreach ($items as $item) {
            $hash = hash('sha256', CanonicalJson::encode([
                'previous' => $hash,
                'fact' => $this->inputFact($item, $historicalCutoff),
            ]));
        }

        return $hash ?? hash('sha256', CanonicalJson::encode(['empty' => 'input']));
    }

    public function outputSlice(
        ?string $previousHash,
        int $organizationId,
        array $itemIds,
        DateTimeInterface $historicalCutoff,
    ): string {
        $records = [
            ...SentPurchaseOrderLineOwner::query()
                ->where('organization_id', $organizationId)
                ->whereIn('purchase_order_item_id', $itemIds)
                ->where('effective_from', '<=', $historicalCutoff)
                ->orderBy('id')
                ->get(['id', 'purchase_order_item_id', 'source_hash'])
                ->map(static fn ($row): array => [
                    'kind' => 'owner',
                    'id' => (int) $row->id,
                    'item_id' => (int) $row->purchase_order_item_id,
                    'source_hash' => (string) $row->source_hash,
                ])
                ->all(),
            ...PurchaseOrderPromiseVersion::query()
                ->where('organization_id', $organizationId)
                ->whereIn('purchase_order_item_id', $itemIds)
                ->where('effective_from', '<=', $historicalCutoff)
                ->orderBy('id')
                ->get(['id', 'purchase_order_item_id', 'source_hash'])
                ->map(static fn ($row): array => [
                    'kind' => 'promise',
                    'id' => (int) $row->id,
                    'item_id' => (int) $row->purchase_order_item_id,
                    'source_hash' => (string) $row->source_hash,
                ])
                ->all(),
            ...SupplyLifecycleEvent::query()
                ->where('organization_id', $organizationId)
                ->whereIn('purchase_order_item_id', $itemIds)
                ->where('occurred_at', '<=', $historicalCutoff)
                ->orderBy('id')
                ->get(['id', 'purchase_order_item_id', 'source_hash'])
                ->map(static fn ($row): array => [
                    'kind' => 'lifecycle',
                    'id' => (int) $row->id,
                    'item_id' => (int) $row->purchase_order_item_id,
                    'source_hash' => (string) $row->source_hash,
                ])
                ->all(),
        ];

        usort($records, static fn (array $left, array $right): int => [
            $left['item_id'],
            match ($left['kind']) {
                'owner' => 1,
                'promise' => 2,
                default => 3,
            },
            $left['id'],
        ] <=> [
            $right['item_id'],
            match ($right['kind']) {
                'owner' => 1,
                'promise' => 2,
                default => 3,
            },
            $right['id'],
        ]);
        $hash = $previousHash;
        foreach ($records as $record) {
            $hash = hash('sha256', CanonicalJson::encode([
                'previous' => $hash,
                'record' => $record,
            ]));
        }

        return $hash ?? hash('sha256', CanonicalJson::encode(['empty' => 'output']));
    }

    /**
     * @return array{processed_count:int,input_hash:?string,output_hash:?string}
     */
    public function recompute(
        int $organizationId,
        int $targetItemId,
        DateTimeInterface $historicalCutoff,
        int $sliceSize = 500,
    ): array {
        $processedCount = 0;
        $inputHash = null;
        $outputHash = null;
        PurchaseOrderItem::query()
            ->with(self::RELATIONS)
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.organization_id', $organizationId)
            ->whereNotNull('purchase_orders.sent_at')
            ->where('purchase_orders.sent_at', '<=', $historicalCutoff)
            ->where('purchase_order_items.id', '<=', $targetItemId)
            ->select('purchase_order_items.*')
            ->orderBy('purchase_order_items.id')
            ->chunkById(
                $sliceSize,
                function (Collection $items) use (
                    $organizationId,
                    &$processedCount,
                    &$inputHash,
                    &$outputHash,
                    $historicalCutoff,
                ): void {
                    $inputHash = $this->inputSlice($inputHash, $items, $historicalCutoff);
                    $outputHash = $this->outputSlice(
                        $outputHash,
                        $organizationId,
                        $items->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                        $historicalCutoff,
                    );
                    $processedCount += $items->count();
                },
                'purchase_order_items.id',
                'id',
            );

        return [
            'processed_count' => $processedCount,
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
        ];
    }

    private function inputFact(PurchaseOrderItem $item, DateTimeInterface $historicalCutoff): array
    {
        $order = $item->purchaseOrder;
        $request = $order->purchaseRequest;
        $siteRequest = $request->siteRequest;
        $itemMetadata = is_array($item->metadata) ? $item->metadata : [];
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];

        return [
            'purchase_order_item' => [
                'id' => (int) $item->id,
                'purchase_order_id' => (int) $item->purchase_order_id,
                'material_id' => $item->material_id === null ? null : (int) $item->material_id,
                'quantity' => (string) $item->quantity,
                'unit' => (string) $item->unit,
                'unit_price' => (string) $item->unit_price,
                'total_price' => (string) $item->total_price,
                'source_version' => $itemMetadata['reporting_source_version'] ?? null,
                'unit_dimension' => $itemMetadata['reporting_unit_dimension'] ?? null,
                'unit_code' => $itemMetadata['reporting_unit_code'] ?? null,
                'conversion_version' => $itemMetadata['reporting_conversion_version'] ?? null,
                'original_promised_at' => $itemMetadata['reporting_original_promised_at'] ?? null,
                'sent_owner_dimensions' => $itemMetadata['reporting_sent_owner_dimensions'] ?? null,
                'purchase_request_line_id' => $itemMetadata['purchase_request_line_id'] ?? null,
                'buyer_id' => $itemMetadata['buyer_id'] ?? null,
                'priority' => $itemMetadata['priority'] ?? null,
                'tax_basis' => $itemMetadata['tax_basis'] ?? null,
                'freight_basis' => $itemMetadata['freight_basis'] ?? null,
            ],
            'purchase_order' => [
                'id' => (int) $order->id,
                'organization_id' => (int) $order->organization_id,
                'purchase_request_id' => (int) $order->purchase_request_id,
                'supplier_id' => $order->supplier_id === null ? null : (int) $order->supplier_id,
                'sent_at' => $order->sent_at?->format(DATE_ATOM),
                'currency' => $order->currency,
                'pricing_source' => $order->pricing_source,
                'warehouse_id' => $orderMetadata['warehouse_id'] ?? null,
                'tax_basis' => $orderMetadata['tax_basis'] ?? null,
                'freight_basis' => $orderMetadata['freight_basis'] ?? null,
                'reporting_sent_at' => $orderMetadata['reporting_sent_at'] ?? null,
                'reporting_confirmed_at' => $this->timestampAtOrBefore(
                    $orderMetadata['reporting_confirmed_at'] ?? null,
                    $historicalCutoff,
                ),
                'reporting_cancelled_at' => $this->timestampAtOrBefore(
                    $orderMetadata['reporting_cancelled_at'] ?? null,
                    $historicalCutoff,
                ),
            ],
            'owner_source' => [
                'request_id' => (int) $request->id,
                'request_organization_id' => (int) $request->organization_id,
                'site_request_id' => $siteRequest === null ? null : (int) $siteRequest->id,
                'site_request_organization_id' => $siteRequest === null
                    ? null
                    : (int) $siteRequest->organization_id,
                'project_id' => $siteRequest === null ? null : (int) $siteRequest->project_id,
            ],
            'receipt_lines' => $item->receiptLines
                ->filter(static function ($line) use ($historicalCutoff): bool {
                    $metadata = is_array($line->metadata) ? $line->metadata : [];
                    $postedAt = $metadata['reporting_posted_at'] ?? null;

                    return is_string($postedAt) && strtotime($postedAt) !== false
                        && strtotime($postedAt) <= $historicalCutoff->getTimestamp();
                })
                ->sortBy('id')
                ->map(static function ($line) use ($historicalCutoff): array {
                    $metadata = is_array($line->metadata) ? $line->metadata : [];

                    return [
                        'id' => (int) $line->id,
                        'purchase_receipt_id' => (int) $line->purchase_receipt_id,
                        'receipt_organization_id' => (int) $line->purchaseReceipt->organization_id,
                        'receipt_purchase_order_id' => (int) $line->purchaseReceipt->purchase_order_id,
                        'warehouse_id' => (int) $line->purchaseReceipt->warehouse_id,
                        'quantity_received' => (string) $line->quantity_received,
                        'price' => (string) $line->price,
                        'total_amount' => (string) $line->total_amount,
                        'source_version' => $metadata['reporting_source_version'] ?? null,
                        'posted_at' => $metadata['reporting_posted_at'] ?? null,
                        'legacy_corrections' => collect($metadata['reporting_return_events'] ?? [])
                            ->filter(static function ($correction) use ($historicalCutoff): bool {
                                $occurredAt = is_array($correction)
                                    ? ($correction['occurred_at'] ?? null)
                                    : null;

                                return is_string($occurredAt) && strtotime($occurredAt) !== false
                                    && strtotime($occurredAt) <= $historicalCutoff->getTimestamp();
                            })
                            ->values()
                            ->all(),
                        'returns' => $line->returns
                            ->filter(
                                static fn ($return): bool => $return->occurred_at->getTimestamp()
                                    <= $historicalCutoff->getTimestamp(),
                            )
                            ->sortBy('id')
                            ->map(static fn ($return): array => [
                                'id' => (int) $return->id,
                                'warehouse_movement_id' => (int) $return->warehouse_movement_id,
                                'supply_lifecycle_event_id' => (int) $return->supply_lifecycle_event_id,
                                'source_type' => (string) $return->source_type,
                                'source_id' => (int) $return->source_id,
                                'source_version' => (int) $return->source_version,
                                'quantity' => (string) $return->quantity,
                                'reason_code' => (string) $return->reason_code,
                                'occurred_at' => $return->occurred_at->format(DATE_ATOM),
                                'idempotency_key' => (string) $return->idempotency_key,
                                'payload_fingerprint' => (string) $return->payload_fingerprint,
                            ])
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function timestampAtOrBefore(mixed $value, DateTimeInterface $cutoff): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false && $timestamp <= $cutoff->getTimestamp() ? $value : null;
    }
}
