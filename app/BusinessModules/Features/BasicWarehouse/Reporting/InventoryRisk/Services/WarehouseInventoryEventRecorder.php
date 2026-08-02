<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class WarehouseInventoryEventRecorder
{
    public function record(
        WarehouseMovement $movement,
        string $eventType,
        ?string $transferPairKey,
        ?array $backfillBasis = null,
    ): WarehouseInventoryEvent {
        if (! in_array($eventType, WarehouseInventoryEvent::EVENT_TYPES, true)) {
            throw new DomainException('Unsupported warehouse inventory event type.');
        }
        if ((int) $movement->organization_id < 1 || (int) $movement->warehouse_id < 1 || (int) $movement->material_id < 1) {
            throw new DomainException('Warehouse inventory event identity is invalid.');
        }
        if (in_array($eventType, ['transfer_in', 'transfer_out'], true) !== ($transferPairKey !== null)) {
            throw new DomainException('Warehouse transfer event requires an exact pair key.');
        }

        $metadata = $backfillBasis ?? (is_array($movement->metadata) ? $movement->metadata : []);
        $sourceVersion = $this->requiredPositiveInt($metadata, 'reporting_source_version');
        $unitDimension = $this->requiredString($metadata, 'unit_dimension');
        $unitCode = $this->requiredString($metadata, 'unit_code');
        $conversionVersion = $this->requiredString($metadata, 'unit_conversion_version');
        $projectId = $this->requiredNullablePositiveInt($metadata, 'reporting_inventory_project_id');
        [$onHandDelta, $reservedDelta] = $this->deltas($movement, $eventType, $metadata);
        [$unitPriceMinor, $currency, $currencySource] = $this->valuation($movement, $metadata);
        $occurredAt = $movement->movement_date;
        if ($occurredAt === null) {
            throw new DomainException('Warehouse inventory event timestamp is required.');
        }

        $attributes = [
            'organization_id' => (int) $movement->organization_id,
            'warehouse_id' => (int) $movement->warehouse_id,
            'project_id' => $projectId,
            'material_id' => (int) $movement->material_id,
            'source_movement_id' => (int) $movement->id,
            'source_version' => $sourceVersion,
            'event_type' => $eventType,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'transfer_pair_key' => $transferPairKey,
            'unit_dimension' => $unitDimension,
            'unit_code' => $unitCode,
            'conversion_version' => $conversionVersion,
            'unit_price_minor' => $unitPriceMinor,
            'currency' => $currency,
            'currency_source' => $currencySource,
            'occurred_at' => $occurredAt,
            'opening_basis' => $this->openingBasis($metadata),
            'source_refs' => array_values(array_filter([
                ['type' => 'warehouse_movement', 'id' => (int) $movement->id],
                $movement->project_material_delivery_id === null ? null : [
                    'type' => 'project_material_delivery',
                    'id' => (int) $movement->project_material_delivery_id,
                ],
            ])),
        ];
        $canonical = $attributes;
        $canonical['occurred_at'] = $occurredAt->format(DATE_ATOM);
        ksort($canonical, SORT_STRING);
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($canonical));

        return DB::transaction(function () use (
            $attributes,
            $eventType,
            $movement,
            $occurredAt,
            $sourceVersion,
        ): WarehouseInventoryEvent {
            $existing = WarehouseInventoryEvent::query()
                ->where('organization_id', $movement->organization_id)
                ->where('source_movement_id', $movement->id)
                ->where('source_version', $sourceVersion)
                ->where('event_type', $eventType)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof WarehouseInventoryEvent) {
                if (! hash_equals((string) $existing->source_hash, $attributes['source_hash'])) {
                    throw new DomainException('Warehouse inventory event idempotency conflict.');
                }

                return $existing;
            }
            $latest = WarehouseInventoryEvent::query()
                ->where('organization_id', $movement->organization_id)
                ->where('warehouse_id', $movement->warehouse_id)
                ->where('project_id', $movement->project_id)
                ->where('material_id', $movement->material_id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($latest instanceof WarehouseInventoryEvent
                && $latest->occurred_at->getTimestamp() > $occurredAt->getTimestamp()) {
                throw new DomainException('Warehouse inventory events must be monotonic within a balance grain.');
            }

            return WarehouseInventoryEvent::query()->create($attributes);
        }, 3);
    }

    private function deltas(WarehouseMovement $movement, string $eventType, array $metadata): array
    {
        $quantity = BigDecimal::of((string) $movement->quantity);
        if (! $quantity->isPositive()) {
            throw new DomainException('Warehouse movement quantity must be positive.');
        }

        return match ($eventType) {
            'receipt', 'transfer_in', 'return' => [(string) $quantity, '0'],
            'issue', 'transfer_out' => [(string) $quantity->negated(), '0'],
            'reservation' => ['0', (string) $quantity],
            'unreservation' => ['0', (string) $quantity->negated()],
            'reserved_issue' => [(string) $quantity->negated(), (string) $quantity->negated()],
            'adjustment' => [
                $this->requiredDecimal($metadata, 'on_hand_delta'),
                $this->decimal($metadata, 'reserved_delta', '0'),
            ],
            default => throw new DomainException('Unsupported warehouse inventory event type.'),
        };
    }

    private function valuation(WarehouseMovement $movement, array $metadata): array
    {
        if ($movement->price === null) {
            return [null, null, null];
        }
        $currency = $metadata['currency'] ?? null;
        $currencySource = $metadata['currency_source'] ?? null;
        if (! is_string($currency)
            || trim($currency) === ''
            || ! is_string($currencySource)
            || trim($currencySource) === '') {
            return [null, null, null];
        }
        $minor = BigDecimal::of((string) $movement->price)->multipliedBy(100);
        if ($minor->isNegative()) {
            throw new DomainException('Warehouse inventory unit price cannot be negative.');
        }

        return [
            $minor->toScale(0, RoundingMode::Unnecessary)->toInt(),
            trim($currency),
            trim($currencySource),
        ];
    }

    private function openingBasis(array $metadata): ?string
    {
        $basis = $metadata['reporting_opening_basis'] ?? null;
        if ($basis === null) {
            return null;
        }
        if (! is_string($basis)
            || ! in_array($basis, ['verified_zero', 'opening_inventory', 'prior_verified_closing'], true)) {
            throw new DomainException('Warehouse inventory opening basis is invalid.');
        }

        return $basis;
    }

    private function requiredString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Warehouse inventory {$key} is required.");
        }

        return trim($value);
    }

    private function requiredPositiveInt(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Warehouse inventory {$key} is required.");
        }

        return $value;
    }

    private function requiredNullablePositiveInt(array $metadata, string $key): ?int
    {
        if (! array_key_exists($key, $metadata)) {
            throw new DomainException("Warehouse inventory {$key} is required.");
        }
        $value = $metadata[$key];
        if ($value !== null && (! is_int($value) || $value < 1)) {
            throw new DomainException("Warehouse inventory {$key} is invalid.");
        }

        return $value;
    }

    private function requiredDecimal(array $metadata, string $key): string
    {
        if (! array_key_exists($key, $metadata)) {
            throw new DomainException("Warehouse inventory {$key} is required.");
        }

        return (string) BigDecimal::of((string) $metadata[$key]);
    }

    private function decimal(array $metadata, string $key, string $default): string
    {
        return array_key_exists($key, $metadata)
            ? (string) BigDecimal::of((string) $metadata[$key])
            : $default;
    }
}
