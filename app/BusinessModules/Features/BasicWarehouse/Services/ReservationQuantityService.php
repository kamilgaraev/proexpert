<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Support\Collection;

final class ReservationQuantityService
{
    public function consumedQuantity(AssetReservation $reservation): float
    {
        return (float) WarehouseMovement::query()
            ->where('organization_id', $reservation->organization_id)
            ->where('movement_type', WarehouseMovement::TYPE_RESERVED_ISSUE)
            ->where('metadata->asset_reservation_id', $reservation->id)
            ->sum('quantity');
    }

    public function remainingQuantity(AssetReservation $reservation): float
    {
        return max((float) $reservation->quantity - $this->consumedQuantity($reservation), 0.0);
    }

    /**
     * @param  Collection<int, AssetReservation>  $reservations
     * @return array<int, array{consumed_quantity: float, remaining_quantity: float}>
     */
    public function quantitiesForReservations(Collection $reservations): array
    {
        if ($reservations->isEmpty()) {
            return [];
        }

        $reservationsById = $reservations->keyBy('id');
        $consumedByReservation = WarehouseMovement::query()
            ->where('organization_id', (int) $reservations->first()->organization_id)
            ->where('movement_type', WarehouseMovement::TYPE_RESERVED_ISSUE)
            ->whereIn(
                'metadata->asset_reservation_id',
                $reservationsById->keys()->map(static fn (int $id): string => (string) $id)->all(),
            )
            ->get(['quantity', 'metadata'])
            ->groupBy(static fn (WarehouseMovement $movement): int => (int) $movement->metadata['asset_reservation_id'])
            ->map(static fn (Collection $movements): float => (float) $movements->sum('quantity'));

        return $reservationsById
            ->mapWithKeys(static function (AssetReservation $reservation) use ($consumedByReservation): array {
                $consumedQuantity = (float) $consumedByReservation->get($reservation->id, 0.0);

                return [
                    $reservation->id => [
                        'consumed_quantity' => $consumedQuantity,
                        'remaining_quantity' => max((float) $reservation->quantity - $consumedQuantity, 0.0),
                    ],
                ];
            })
            ->all();
    }
}
