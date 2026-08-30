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

        return WarehouseMovement::query()
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
            ->whereDate('movement_date', $movement->movement_date)
            ->orderBy('id')
            ->get();
    }
}
