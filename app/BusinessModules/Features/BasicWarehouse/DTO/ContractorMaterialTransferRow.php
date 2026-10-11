<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\DTO;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ContractorMaterialTransferRow
{
    public static function fromMovement(WarehouseMovement $movement, ?string $contractorName = null): array
    {
        return [
            'movement_id' => (int) $movement->id,
            'contractor_id' => (int) ($movement->metadata['contractor_id'] ?? 0),
            'contractor_name' => $movement->metadata['contractor_name'] ?? $contractorName
                ?? trans_message('warehouse_basic.tolling_report.unknown_contractor'),
            'material_id' => (int) $movement->material_id,
            'material_name' => $movement->material->name,
            'material_code' => $movement->material->code,
            'measurement_unit' => $movement->material->measurementUnit?->short_name
                ?? $movement->material->measurementUnit?->name,
            'quantity' => (float) $movement->quantity,
            'price' => (float) $movement->price,
            'total_value' => (float) (string) BigDecimal::of((string) $movement->quantity)
                ->multipliedBy((string) $movement->price)->toScale(2, RoundingMode::HALF_UP),
            'warehouse_id' => (int) $movement->warehouse_id,
            'warehouse_name' => $movement->warehouse->name,
            'project_id' => $movement->project_id,
            'project_name' => $movement->project?->name,
            'document_number' => $movement->movementDocumentNumber(),
            'movement_date' => $movement->movement_date->toDateTimeString(),
            'reason' => $movement->reason,
        ];
    }
}
