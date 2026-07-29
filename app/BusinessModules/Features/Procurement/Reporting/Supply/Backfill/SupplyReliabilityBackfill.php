<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\PurchaseOrderPromiseVersionRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder;
use App\Support\Reporting\OwnerBackfillBatch;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class SupplyReliabilityBackfill
{
    private const MAX_SLICE = 500;

    public function __construct(
        private PurchaseOrderPromiseVersionRecorder $promises,
        private SupplyLifecycleEventRecorder $events,
    ) {}

    public function backfillSlice(int $organizationId, int $cursor, int $limit = self::MAX_SLICE): OwnerBackfillBatch
    {
        $limit = min(self::MAX_SLICE, max(1, $limit));
        $items = PurchaseOrderItem::query()
            ->with(['purchaseOrder.purchaseRequest', 'receiptLines.purchaseReceipt'])
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.organization_id', $organizationId)
            ->whereNotNull('purchase_orders.sent_at')
            ->where('purchase_order_items.id', '>', $cursor)
            ->select('purchase_order_items.*')
            ->orderBy('purchase_order_items.id')
            ->limit($limit)
            ->get();
        $input = [];
        $projected = [];
        $gaps = 0;
        foreach ($items as $item) {
            $order = $item->purchaseOrder;
            $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $promiseEvidence = $metadata['reporting_original_promised_at']
                ?? $orderMetadata['delivery_date_at_first_send']
                ?? null;
            $input[] = [
                'item_id' => (int) $item->id,
                'sent_at' => $order->sent_at?->format(DATE_ATOM),
                'promise_evidence' => $promiseEvidence,
            ];
            if (! is_string($promiseEvidence)
                || trim($promiseEvidence) === ''
                || $order->sent_at === null
                || ! is_string($orderMetadata['tax_basis'] ?? null)
                || ! is_string($orderMetadata['freight_basis'] ?? null)) {
                $gaps++;

                continue;
            }
            try {
                $unitIdentity = 'unit-code:'.hash('sha256', mb_strtolower(trim((string) $item->unit)));
                $basis = array_merge([
                    'reporting_source_version' => 1,
                    'unit_dimension' => $unitIdentity,
                    'unit_conversion_version' => $unitIdentity.':identity-v1',
                    'tax_basis' => $orderMetadata['tax_basis'],
                    'freight_basis' => $orderMetadata['freight_basis'],
                ], $metadata);
                $sentAt = CarbonImmutable::instance($order->sent_at);
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
                    evidence: ['backfill' => true],
                );
                $projected[] = (int) $sent->id;
                foreach ($item->receiptLines->sortBy('id') as $line) {
                    if ($line->purchaseReceipt?->receipt_date === null) {
                        $gaps++;

                        continue;
                    }
                    $receipt = $this->events->receiptBackfill(
                        $line,
                        CarbonImmutable::parse($line->purchaseReceipt->receipt_date)->endOfDay(),
                        1,
                    );
                    $projected[] = (int) $receipt->id;
                }
            } catch (Throwable) {
                $gaps++;
            }
        }
        $nextCursor = $items->isEmpty() ? $cursor : (int) $items->last()->id;
        $output = SupplyLifecycleEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projected)
            ->orderBy('id')
            ->pluck('source_hash')
            ->all();

        return new OwnerBackfillBatch(
            $items->count(),
            count($projected),
            $gaps,
            $nextCursor,
            $items->count() < $limit,
            hash('sha256', CanonicalJson::encode($input)),
            hash('sha256', CanonicalJson::encode($output)),
        );
    }
}
