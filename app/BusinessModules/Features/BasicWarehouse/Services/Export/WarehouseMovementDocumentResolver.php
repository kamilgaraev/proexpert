<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\Collection;

final class WarehouseMovementDocumentResolver
{
    /** @return Collection<int, WarehouseMovement> */
    public function resolve(WarehouseMovement $movement): Collection
    {
        if ($movement->document_number === null || trim($movement->document_number) === '') {
            return $movement->newCollection([$movement]);
        }

        $query = WarehouseMovement::query()
            ->where('organization_id', $movement->organization_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('movement_type', $movement->movement_type)
            ->where('document_number', $movement->document_number)
            ->where('project_id', $movement->project_id)
            ->where('from_warehouse_id', $movement->from_warehouse_id)
            ->where('to_warehouse_id', $movement->to_warehouse_id)
            ->where('related_user_id', $movement->related_user_id)
            ->where('user_id', $movement->user_id)
            ->where('operation_category', $movement->operation_category)
            ->where('reason', $movement->reason)
            ->whereDate('movement_date', $movement->movement_date)
            ->orderBy('id');

        // Одинаковый пользовательский номер не объединяет документы разных подрядчиков.
        if ($movement->isContractorTransferOut()) {
            $query->where('metadata->is_contractor_transfer', true)
                ->where('metadata->contractor_id', $movement->metadata['contractor_id'] ?? null);
        } elseif ($movement->isCustomerMaterialReceipt()) {
            $query->where('metadata->is_customer_transfer', true)
                ->where('metadata->source_organization_id', $movement->metadata['source_organization_id'] ?? null);
        } else {
            foreach (['is_contractor_transfer', 'is_customer_transfer'] as $flag) {
                $query->where(static fn ($scope) => $scope->whereNull('metadata->'.$flag)->orWhere('metadata->'.$flag, false));
            }
        }

        return $query->get();
    }
}
