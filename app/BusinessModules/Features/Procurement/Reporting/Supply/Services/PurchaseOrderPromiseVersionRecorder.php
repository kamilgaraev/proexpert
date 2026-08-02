<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PurchaseOrderPromiseVersionRecorder
{
    public function captureOriginal(
        PurchaseOrderItem $item,
        CarbonImmutable $promisedAt,
    ): PurchaseOrderPromiseVersion {
        return DB::transaction(function () use ($item, $promisedAt): PurchaseOrderPromiseVersion {
            $existing = PurchaseOrderPromiseVersion::query()
                ->where('organization_id', $this->organizationId($item))
                ->where('purchase_order_item_id', $item->getKey())
                ->where('promise_version', 1)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PurchaseOrderPromiseVersion) {
                if (! $existing->promised_at->equalTo($promisedAt)) {
                    throw new DomainException('Original purchase order promise cannot be changed.');
                }

                return $existing;
            }
            if ($item->purchaseOrder->sent_at !== null) {
                throw new DomainException('Original promise must be captured before the order is sent.');
            }

            return $this->create($item, $promisedAt, 1, null, CarbonImmutable::now('UTC'));
        });
    }

    public function captureBackfillOriginal(
        PurchaseOrderItem $item,
        CarbonImmutable $promisedAt,
        CarbonImmutable $sentAt,
        array $basis,
    ): PurchaseOrderPromiseVersion {
        return DB::transaction(function () use ($basis, $item, $promisedAt, $sentAt): PurchaseOrderPromiseVersion {
            $existing = PurchaseOrderPromiseVersion::query()
                ->where('organization_id', $this->organizationId($item))
                ->where('purchase_order_item_id', $item->getKey())
                ->where('promise_version', 1)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PurchaseOrderPromiseVersion) {
                if (! $existing->promised_at->equalTo($promisedAt)) {
                    throw new DomainException('Backfilled original promise conflicts with the pinned version.');
                }

                return $existing;
            }

            return $this->create($item, $promisedAt, 1, null, $sentAt, $basis);
        });
    }

    public function supersede(
        PurchaseOrderItem $item,
        CarbonImmutable $promisedAt,
    ): PurchaseOrderPromiseVersion {
        return DB::transaction(function () use ($item, $promisedAt): PurchaseOrderPromiseVersion {
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $sourceVersion = $this->requiredPositiveInt($metadata, 'reporting_source_version');
            $existing = PurchaseOrderPromiseVersion::query()
                ->where('organization_id', $this->organizationId($item))
                ->where('purchase_order_item_id', $item->getKey())
                ->where('source_version', $sourceVersion)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PurchaseOrderPromiseVersion) {
                if (! $existing->promised_at->equalTo($promisedAt)) {
                    throw new DomainException('Purchase order promise source version is already pinned.');
                }

                return $existing;
            }

            $previous = PurchaseOrderPromiseVersion::query()
                ->where('organization_id', $this->organizationId($item))
                ->where('purchase_order_item_id', $item->getKey())
                ->orderByDesc('promise_version')
                ->lockForUpdate()
                ->first();
            if (! $previous instanceof PurchaseOrderPromiseVersion) {
                throw new DomainException('Original promise is required before superseding it.');
            }
            if ($sourceVersion <= (int) $previous->getAttribute('source_version')) {
                throw new DomainException('Purchase order promise source version must be monotonic.');
            }

            return $this->create(
                $item,
                $promisedAt,
                ((int) $previous->promise_version) + 1,
                (int) $previous->id,
                CarbonImmutable::now('UTC'),
            );
        });
    }

    private function create(
        PurchaseOrderItem $item,
        CarbonImmutable $promisedAt,
        int $version,
        ?int $supersedesId,
        CarbonImmutable $effectiveFrom,
        ?array $backfillBasis = null,
    ): PurchaseOrderPromiseVersion {
        $order = $item->purchaseOrder;
        $metadata = $backfillBasis ?? (is_array($item->metadata) ? $item->metadata : []);
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
        $unitDimension = $this->requiredString($metadata, 'unit_dimension');
        $conversionVersion = $this->requiredString($metadata, 'unit_conversion_version');
        $taxBasis = $this->requiredString($metadata + $orderMetadata, 'tax_basis');
        $freightBasis = $this->requiredString($metadata + $orderMetadata, 'freight_basis');
        $sourceVersion = $this->requiredPositiveInt($metadata, 'reporting_source_version');
        $projectId = $order->purchaseRequest?->siteRequest?->project_id;
        $orderedValueMinor = BigDecimal::of((string) $item->total_price)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
        $valueBasis = implode(':', [
            trim((string) $order->currency),
            trim((string) $order->pricing_source),
            $taxBasis,
            $freightBasis,
        ]);
        $attributes = [
            'organization_id' => (int) $order->organization_id,
            'purchase_order_id' => (int) $order->id,
            'purchase_order_item_id' => (int) $item->id,
            'promise_version' => $version,
            'supplier_id' => $order->supplier_id,
            'project_id' => $projectId,
            'warehouse_id' => $orderMetadata['warehouse_id'] ?? null,
            'material_id' => $item->material_id,
            'buyer_id' => $order->purchaseRequest?->assigned_to,
            'priority' => $order->purchaseRequest?->siteRequest?->priority?->value,
            'ordered_quantity' => (string) $item->quantity,
            'ordered_value_minor' => $orderedValueMinor,
            'value_basis' => $valueBasis,
            'unit_dimension' => $unitDimension,
            'unit_code' => trim((string) $item->unit),
            'conversion_version' => $conversionVersion,
            'promised_at' => $promisedAt,
            'promise_timezone' => $promisedAt->getTimezone()->getName(),
            'currency' => $order->currency,
            'currency_source' => $order->pricing_source,
            'tax_basis' => $taxBasis,
            'freight_basis' => $freightBasis,
            'source_version' => $sourceVersion,
            'supersedes_id' => $supersedesId,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ];
        $canonical = $attributes;
        foreach (['promised_at', 'effective_from'] as $field) {
            $canonical[$field] = $canonical[$field]->format(DATE_ATOM);
        }
        ksort($canonical, SORT_STRING);
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($canonical));

        return PurchaseOrderPromiseVersion::query()->create($attributes);
    }

    private function organizationId(PurchaseOrderItem $item): int
    {
        $organizationId = (int) $item->purchaseOrder->organization_id;
        if ($organizationId < 1 || (int) $item->getKey() < 1) {
            throw new DomainException('Purchase order promise identity is invalid.');
        }

        return $organizationId;
    }

    private function requiredString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Purchase order promise {$key} is required.");
        }

        return trim($value);
    }

    private function requiredPositiveInt(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Purchase order promise {$key} is required.");
        }

        return $value;
    }
}
