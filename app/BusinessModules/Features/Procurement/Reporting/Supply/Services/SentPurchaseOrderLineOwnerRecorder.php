<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SentPurchaseOrderLineOwnerRecorder
{
    public function record(PurchaseOrderItem $item, CarbonImmutable $sentAt): SentPurchaseOrderLineOwner
    {
        return $this->capture($item, $sentAt, null);
    }

    public function recordBackfill(
        PurchaseOrderItem $item,
        CarbonImmutable $sentAt,
        array $capturedDimensions,
    ): SentPurchaseOrderLineOwner {
        return $this->capture($item, $sentAt, $capturedDimensions);
    }

    private function capture(
        PurchaseOrderItem $item,
        CarbonImmutable $sentAt,
        ?array $capturedDimensions,
    ): SentPurchaseOrderLineOwner {
        $item->loadMissing('purchaseOrder.purchaseRequest.siteRequest');
        $order = $item->purchaseOrder;
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
        $itemMetadata = is_array($item->metadata) ? $item->metadata : [];
        $dimensions = $capturedDimensions ?? [
            'purchase_request_id' => (int) $order->purchase_request_id,
            'purchase_request_line_id' => $this->requiredPositiveInt(
                $itemMetadata,
                'purchase_request_line_id',
            ),
            'project_id' => (int) $order->purchaseRequest?->siteRequest?->project_id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $orderMetadata['warehouse_id'] ?? null,
            'material_id' => $item->material_id,
            'buyer_id' => $order->purchaseRequest?->assigned_to,
            'priority' => $order->purchaseRequest?->siteRequest?->priority?->value,
            'unit_dimension' => $this->requiredString($itemMetadata, 'unit_dimension'),
            'unit_code' => trim((string) $item->unit),
            'conversion_version' => $this->requiredString($itemMetadata, 'unit_conversion_version'),
            'source_version' => $this->requiredPositiveInt($itemMetadata, 'reporting_source_version'),
        ];
        $attributes = [
            'organization_id' => (int) $order->organization_id,
            'purchase_order_id' => (int) $order->id,
            'purchase_order_item_id' => (int) $item->id,
            'source_version' => $this->requiredPositiveInt($dimensions, 'source_version'),
            'purchase_request_id' => $this->requiredPositiveInt($dimensions, 'purchase_request_id'),
            'purchase_request_line_id' => $this->requiredPositiveInt(
                $dimensions,
                'purchase_request_line_id',
            ),
            'project_id' => $this->requiredPositiveInt($dimensions, 'project_id'),
            'supplier_id' => $dimensions['supplier_id'] ?? null,
            'warehouse_id' => $dimensions['warehouse_id'] ?? null,
            'material_id' => $dimensions['material_id'] ?? null,
            'buyer_id' => $dimensions['buyer_id'] ?? null,
            'priority' => $dimensions['priority'] ?? null,
            'unit_dimension' => $this->requiredString($dimensions, 'unit_dimension'),
            'unit_code' => $this->requiredString($dimensions, 'unit_code'),
            'conversion_version' => $this->requiredString($dimensions, 'conversion_version'),
            'effective_from' => $sentAt->utc(),
        ];
        if ($attributes['organization_id'] < 1
            || $attributes['purchase_order_id'] < 1
            || $attributes['purchase_order_item_id'] < 1
            || $attributes['purchase_request_id'] < 1
            || $attributes['project_id'] < 1
            || $attributes['unit_code'] === '') {
            throw new DomainException('Sent purchase order line owner dimensions are incomplete.');
        }
        $canonical = $attributes;
        $canonical['effective_from'] = $attributes['effective_from']->format(DATE_ATOM);
        ksort($canonical, SORT_STRING);
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($canonical));

        return DB::transaction(function () use ($attributes): SentPurchaseOrderLineOwner {
            $existing = SentPurchaseOrderLineOwner::query()
                ->where('organization_id', $attributes['organization_id'])
                ->where('purchase_order_item_id', $attributes['purchase_order_item_id'])
                ->where('source_version', $attributes['source_version'])
                ->lockForUpdate()
                ->first();
            if ($existing instanceof SentPurchaseOrderLineOwner) {
                if (! hash_equals((string) $existing->source_hash, $attributes['source_hash'])) {
                    throw new DomainException('Sent purchase order line owner identity conflict.');
                }

                return $existing;
            }

            return SentPurchaseOrderLineOwner::query()->create($attributes);
        }, 3);
    }

    private function requiredString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Sent purchase order line {$key} is required.");
        }

        return trim($value);
    }

    private function requiredPositiveInt(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Sent purchase order line {$key} is required.");
        }

        return $value;
    }
}
