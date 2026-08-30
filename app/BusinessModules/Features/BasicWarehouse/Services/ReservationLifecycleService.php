<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function trans_message;

final class ReservationLifecycleService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly ReservationQuantityService $quantityService,
    ) {}

    public function findForOrganization(int $organizationId, int $reservationId): AssetReservation
    {
        return AssetReservation::query()
            ->where('organization_id', $organizationId)
            ->with(['organization', 'material.measurementUnit', 'warehouse', 'project', 'reservedBy'])
            ->findOrFail($reservationId);
    }

    /** @return Collection<int, WarehouseMovement> */
    public function movementsForExport(AssetReservation $reservation): Collection
    {
        return WarehouseMovement::query()
            ->where('organization_id', $reservation->organization_id)
            ->where('movement_type', WarehouseMovement::TYPE_RESERVED_ISSUE)
            ->where('metadata->asset_reservation_id', $reservation->id)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
    }

    public function consumedQuantity(AssetReservation $reservation): float
    {
        return $this->quantityService->consumedQuantity($reservation);
    }

    public function remainingQuantity(AssetReservation $reservation): float
    {
        return $this->quantityService->remainingQuantity($reservation);
    }

    /**
     * @param  Collection<int, AssetReservation>  $reservations
     * @return array<int, array{consumed_quantity: float, remaining_quantity: float}>
     */
    public function quantitiesForReservations(Collection $reservations): array
    {
        return $this->quantityService->quantitiesForReservations($reservations);
    }

    public function expireDue(int $limit = 200): int
    {
        $reservationIds = AssetReservation::query()
            ->expired()
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
        $expiredCount = 0;

        foreach ($reservationIds as $reservationId) {
            try {
                $this->warehouseService->expireReservation((int) $reservationId);
                $expiredCount++;
            } catch (ModelNotFoundException) {
                continue;
            }
        }

        return $expiredCount;
    }

    public function consume(
        int $organizationId,
        int $reservationId,
        float $quantity,
        array $metadata,
    ): WarehouseMovement {
        return DB::transaction(function () use ($organizationId, $reservationId, $quantity, $metadata): WarehouseMovement {
            $reservation = AssetReservation::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($reservationId);
            $idempotencyKey = trim((string) ($metadata['idempotency_key'] ?? ''));
            $fingerprint = WarehouseOperationIdempotency::fingerprint('reservation_consume', [
                'reservation_id' => $reservationId,
                'quantity' => $quantity,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
            ]);
            $existingMovement = $idempotencyKey === ''
                ? null
                : WarehouseMovement::query()
                    ->where('organization_id', $organizationId)
                    ->where('movement_type', WarehouseMovement::TYPE_RESERVED_ISSUE)
                    ->where('metadata->asset_reservation_id', $reservationId)
                    ->where('metadata->idempotency_key', $idempotencyKey)
                    ->first();

            if ($existingMovement !== null) {
                if (($existingMovement->metadata['idempotency_fingerprint'] ?? null) !== $fingerprint) {
                    throw new WarehouseOperationIdempotencyConflictException(
                        trans_message('warehouse_basic.idempotency_conflict')
                    );
                }

                return $existingMovement;
            }

            if ($reservation->status !== AssetReservation::STATUS_ACTIVE) {
                throw new InvalidArgumentException(trans_message('basic_warehouse.reservation.not_active'));
            }

            if ($reservation->isExpired()) {
                throw new InvalidArgumentException(trans_message('basic_warehouse.reservation.expired'));
            }

            $remainingQuantity = $this->remainingQuantity($reservation);
            if ($quantity <= 0 || $quantity > $remainingQuantity + 0.000001) {
                throw new InvalidArgumentException(trans_message('basic_warehouse.reservation.insufficient_remaining', [
                    'remaining' => $remainingQuantity,
                    'requested' => $quantity,
                ]));
            }

            $movement = $this->warehouseService->writeOffReservedAsset(
                $organizationId,
                (int) $reservation->warehouse_id,
                (int) $reservation->material_id,
                $quantity,
                array_merge($metadata, [
                    'asset_reservation_id' => $reservation->id,
                    'project_id' => $reservation->project_id,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $fingerprint,
                ]),
            );

            if ($quantity >= $remainingQuantity - 0.000001) {
                $reservation->forceFill([
                    'status' => AssetReservation::STATUS_FULFILLED,
                    'fulfilled_at' => now(),
                ])->save();
            }

            return $movement;
        }, 3);
    }
}
