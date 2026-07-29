<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseInventoryEventRecorder;
use App\Models\Material;
use App\Support\Reporting\OwnerBackfillBatch;
use Brick\Math\BigDecimal;
use DomainException;
use Throwable;

final readonly class InventoryRiskBackfill
{
    private const MAX_SLICE = 500;

    public function __construct(private WarehouseInventoryEventRecorder $events) {}

    public function backfillSlice(
        int $organizationId,
        string $cursor = '',
        int $limit = self::MAX_SLICE,
    ): OwnerBackfillBatch {
        $limit = min(self::MAX_SLICE, max(1, $limit));
        $position = $this->decodeCursor($cursor);
        $query = WarehouseMovement::query()
            ->where('organization_id', $organizationId);
        if ($position !== null) {
            $query->where(function ($builder) use ($position): void {
                $builder
                    ->where('movement_date', '>', $position['movement_date'])
                    ->orWhere(function ($sameDate) use ($position): void {
                        $sameDate
                            ->where('movement_date', $position['movement_date'])
                            ->where('id', '>', $position['id']);
                    });
            });
        }
        $movements = $query
            ->orderBy('movement_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $materials = Material::query()
            ->with('measurementUnit')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $movements->pluck('material_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $input = [];
        $projected = [];
        $gaps = 0;
        foreach ($movements as $movement) {
            $metadata = is_array($movement->metadata) ? $movement->metadata : [];
            $eventType = $this->eventType((string) $movement->movement_type);
            $pairKey = in_array($eventType, ['transfer_in', 'transfer_out'], true)
                ? ($metadata['transfer_pair_key'] ?? null)
                : null;
            $material = $materials->get($movement->material_id);
            $unit = $material?->measurementUnit;
            $openingBasis = $metadata['reporting_opening_basis'] ?? $this->verifiedOpeningBasis($movement);
            $input[] = [
                'movement_id' => (int) $movement->id,
                'movement_type' => $movement->movement_type,
                'movement_date' => $movement->movement_date?->format(DATE_ATOM),
                'opening_basis' => $openingBasis,
            ];
            if ($eventType === null
                || $movement->movement_date === null
                || $unit === null
                || (in_array($eventType, ['transfer_in', 'transfer_out'], true)
                    && (! is_string($pairKey) || trim($pairKey) === ''))
                || ($movement->price !== null
                    && (! is_string($metadata['currency'] ?? null)
                        || ! is_string($metadata['currency_source'] ?? null)))) {
                $gaps++;

                continue;
            }
            try {
                $unitIdentity = 'measurement-unit:'.$unit->getKey();
                $basis = array_merge([
                    'reporting_source_version' => 1,
                    'unit_dimension' => $unitIdentity,
                    'unit_code' => (string) $unit->short_name,
                    'unit_conversion_version' => $unitIdentity.':identity-v1',
                    'reporting_opening_basis' => $openingBasis,
                ], $metadata);
                $record = $this->events->record($movement, $eventType, $pairKey, $basis);
                $projected[] = (int) $record->id;
            } catch (Throwable) {
                $gaps++;
            }
        }
        $lastMovement = $movements->last();
        $nextCursor = $lastMovement instanceof WarehouseMovement
            ? CanonicalJson::encode([
                'id' => (int) $lastMovement->id,
                'movement_date' => $lastMovement->movement_date?->format('Y-m-d\TH:i:s.uP'),
            ])
            : $cursor;
        $output = WarehouseInventoryEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projected)
            ->orderBy('id')
            ->pluck('source_hash')
            ->all();

        return new OwnerBackfillBatch(
            $movements->count(),
            count($projected),
            $gaps,
            $nextCursor,
            $movements->count() < $limit,
            hash('sha256', CanonicalJson::encode($input)),
            hash('sha256', CanonicalJson::encode($output)),
        );
    }

    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }
        $position = json_decode($cursor, true);
        if (! is_array($position)
            || ! is_string($position['movement_date'] ?? null)
            || ! is_int($position['id'] ?? null)
            || $position['id'] < 1) {
            throw new DomainException('Inventory risk backfill cursor is invalid.');
        }

        return [
            'movement_date' => $position['movement_date'],
            'id' => $position['id'],
        ];
    }

    private function eventType(string $movementType): ?string
    {
        return match ($movementType) {
            'receipt' => 'receipt',
            'write_off' => 'issue',
            'transfer_in' => 'transfer_in',
            'transfer_out' => 'transfer_out',
            'return' => 'return',
            'adjustment' => 'adjustment',
            'reservation' => 'reservation',
            'unreservation' => 'unreservation',
            'reserved_issue' => 'reserved_issue',
            default => null,
        };
    }

    private function verifiedOpeningBasis(WarehouseMovement $movement): ?string
    {
        $history = WarehouseMovement::query()
            ->where('organization_id', $movement->organization_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('project_id', $movement->project_id)
            ->where('material_id', $movement->material_id)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
        $first = $history->first();
        if (! $first instanceof WarehouseMovement
            || (int) $first->id !== (int) $movement->id
            || $first->movement_type !== 'receipt') {
            return null;
        }
        $onHand = BigDecimal::zero();
        foreach ($history as $item) {
            $quantity = BigDecimal::of((string) $item->quantity);
            $onHand = match ((string) $item->movement_type) {
                'receipt', 'transfer_in', 'return' => $onHand->plus($quantity),
                'write_off', 'transfer_out', 'reserved_issue' => $onHand->minus($quantity),
                'adjustment' => $onHand->plus((string) (($item->metadata ?? [])['on_hand_delta'] ?? 0)),
                'reservation', 'unreservation' => $onHand,
                default => BigDecimal::of('-1'),
            };
            if ($onHand->isNegative()) {
                return null;
            }
        }
        $current = WarehouseBalance::query()
            ->where('organization_id', $movement->organization_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('material_id', $movement->material_id)
            ->get()
            ->reduce(
                static fn (BigDecimal $total, WarehouseBalance $balance): BigDecimal => $total
                    ->plus((string) $balance->available_quantity)
                    ->plus((string) $balance->reserved_quantity),
                BigDecimal::zero(),
            );

        return $onHand->isEqualTo($current) ? 'verified_zero' : null;
    }
}
