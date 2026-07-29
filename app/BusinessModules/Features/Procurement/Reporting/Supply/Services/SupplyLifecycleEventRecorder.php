<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SupplyLifecycleEventRecorder
{
    public function receipt(
        PurchaseReceiptLine $line,
        CarbonImmutable $occurredAt,
    ): SupplyLifecycleEvent {
        return $this->recordLineEvent($line, 'received', (string) $line->quantity_received, $occurredAt);
    }

    public function receiptBackfill(
        PurchaseReceiptLine $line,
        CarbonImmutable $occurredAt,
        int $sourceVersion,
    ): SupplyLifecycleEvent {
        return $this->recordLineEvent(
            $line,
            'received',
            (string) $line->quantity_received,
            $occurredAt,
            sourceVersion: $sourceVersion,
        );
    }

    public function reversal(
        PurchaseReceiptLine $line,
        string $reasonCode,
        CarbonImmutable $occurredAt,
    ): SupplyLifecycleEvent {
        $original = SupplyLifecycleEvent::query()
            ->where('organization_id', $this->organizationId($line))
            ->where('source_type', 'purchase_receipt_line')
            ->where('source_id', $line->getKey())
            ->where('event_type', 'received')
            ->first();
        if (! $original instanceof SupplyLifecycleEvent) {
            throw new DomainException('Receipt event is required before reversal.');
        }

        return $this->recordLineEvent(
            $line,
            'receipt_reversed',
            '-'.ltrim((string) $line->quantity_received, '+-'),
            $occurredAt,
            $reasonCode,
            (int) $original->id,
        );
    }

    public function record(
        PurchaseOrderPromiseVersion $promise,
        string $eventType,
        string $sourceType,
        int $sourceId,
        int $sourceVersion,
        string $signedQuantity,
        CarbonImmutable $occurredAt,
        string $idempotencyKey,
        ?string $reasonCode = null,
        ?int $reversedEventId = null,
        array $evidence = [],
    ): SupplyLifecycleEvent {
        if (! in_array($eventType, SupplyLifecycleEvent::EVENT_TYPES, true)) {
            throw new DomainException('Unsupported supply lifecycle event type.');
        }
        if ($sourceId < 1 || $sourceVersion < 1 || trim($idempotencyKey) === '') {
            throw new DomainException('Supply lifecycle source identity is invalid.');
        }
        $quantity = BigDecimal::of($signedQuantity);
        if (($eventType === 'received' && ! $quantity->isPositive())
            || (in_array($eventType, ['receipt_reversed', 'returned'], true) && ! $quantity->isNegative())
            || (in_array($eventType, ['sent', 'confirmed', 'cancelled'], true) && ! $quantity->isZero())) {
            throw new DomainException('Supply lifecycle event quantity sign is invalid.');
        }
        if (($eventType === 'receipt_reversed') !== ($reversedEventId !== null)) {
            throw new DomainException('Supply receipt reversal identity is invalid.');
        }

        $attributes = [
            'organization_id' => (int) $promise->organization_id,
            'purchase_order_id' => (int) $promise->purchase_order_id,
            'purchase_order_item_id' => (int) $promise->purchase_order_item_id,
            'promise_version_id' => (int) $promise->id,
            'event_type' => $eventType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'signed_quantity' => $signedQuantity,
            'unit_dimension' => $promise->unit_dimension,
            'unit_code' => $promise->unit_code,
            'conversion_version' => $promise->conversion_version,
            'occurred_at' => $occurredAt,
            'reversed_event_id' => $reversedEventId,
            'reason_code' => $reasonCode,
            'idempotency_key' => $idempotencyKey,
            'evidence' => $evidence,
        ];
        $canonical = $attributes;
        $canonical['occurred_at'] = $occurredAt->format(DATE_ATOM);
        ksort($canonical, SORT_STRING);
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($canonical));

        return DB::transaction(function () use (
            $attributes,
            $eventType,
            $idempotencyKey,
            $occurredAt,
            $promise,
            $reversedEventId,
        ): SupplyLifecycleEvent {
            $existing = SupplyLifecycleEvent::query()
                ->where('organization_id', $promise->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof SupplyLifecycleEvent) {
                if (! hash_equals((string) $existing->source_hash, $attributes['source_hash'])) {
                    throw new DomainException('Supply lifecycle event idempotency conflict.');
                }

                return $existing;
            }
            $latest = SupplyLifecycleEvent::query()
                ->where('organization_id', $promise->organization_id)
                ->where('purchase_order_item_id', $promise->purchase_order_item_id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($latest instanceof SupplyLifecycleEvent
                && $latest->occurred_at->getTimestamp() > $occurredAt->getTimestamp()) {
                throw new DomainException('Supply lifecycle events must be monotonic.');
            }
            if ($eventType === 'receipt_reversed') {
                $receipt = SupplyLifecycleEvent::query()
                    ->whereKey($reversedEventId)
                    ->where('organization_id', $promise->organization_id)
                    ->where('purchase_order_item_id', $promise->purchase_order_item_id)
                    ->where('event_type', 'received')
                    ->lockForUpdate()
                    ->first();
                if (! $receipt instanceof SupplyLifecycleEvent) {
                    throw new DomainException('Supply receipt reversal must reference an earlier receipt.');
                }
            }

            return SupplyLifecycleEvent::query()->create($attributes);
        }, 3);
    }

    private function recordLineEvent(
        PurchaseReceiptLine $line,
        string $eventType,
        string $quantity,
        CarbonImmutable $occurredAt,
        ?string $reasonCode = null,
        ?int $reversedEventId = null,
        ?int $sourceVersion = null,
    ): SupplyLifecycleEvent {
        $item = $line->purchaseOrderItem;
        $promise = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $this->organizationId($line))
            ->where('purchase_order_item_id', $item->getKey())
            ->where('promise_version', 1)
            ->first();
        if (! $promise instanceof PurchaseOrderPromiseVersion) {
            throw new DomainException('Original purchase order promise is required before receipt.');
        }
        $metadata = is_array($line->metadata) ? $line->metadata : [];
        $sourceVersion ??= $metadata['reporting_source_version'] ?? null;
        if (! is_int($sourceVersion) || $sourceVersion < 1) {
            throw new DomainException('Receipt reporting source version is required.');
        }

        return $this->record(
            $promise,
            $eventType,
            'purchase_receipt_line',
            (int) $line->getKey(),
            $sourceVersion,
            $quantity,
            $occurredAt,
            'purchase_receipt_line:'.$line->getKey().':'.$sourceVersion.':'.$eventType,
            $reasonCode,
            $reversedEventId,
            evidence: ['purchase_receipt_id' => (int) $line->purchase_receipt_id],
        );
    }

    private function organizationId(PurchaseReceiptLine $line): int
    {
        $organizationId = (int) $line->purchaseReceipt->organization_id;
        if ($organizationId < 1 || (int) $line->getKey() < 1) {
            throw new DomainException('Purchase receipt line identity is invalid.');
        }

        return $organizationId;
    }
}
