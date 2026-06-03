<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Resources;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WarehouseCustodyBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WarehouseBalance $balance */
        $balance = $this->resource;
        $warehouse = $balance->warehouse;
        $material = $balance->material;
        $project = $warehouse?->project;
        $responsibleUser = $warehouse?->responsibleUser;

        return [
            'id' => $balance->id,
            'project_id' => $warehouse?->project_id,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'custody_warehouse_id' => $balance->warehouse_id,
            'custody_warehouse' => $warehouse ? [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'warehouse_type' => $warehouse->warehouse_type,
            ] : null,
            'responsible_user_id' => $warehouse?->responsible_user_id,
            'responsible_user' => $responsibleUser ? [
                'id' => $responsibleUser->id,
                'name' => $responsibleUser->name,
                'email' => $responsibleUser->email,
            ] : null,
            'material_id' => $balance->material_id,
            'material' => $material ? [
                'id' => $material->id,
                'name' => $material->name,
                'code' => $material->code,
                'measurement_unit' => $material->measurementUnit ? [
                    'id' => $material->measurementUnit->id,
                    'name' => $material->measurementUnit->name,
                    'short_name' => $material->measurementUnit->short_name,
                ] : null,
            ] : null,
            'available_quantity' => (float) $balance->available_quantity,
            'reserved_quantity' => (float) $balance->reserved_quantity,
            'incoming_quantity' => 0.0,
            'total_quantity' => (float) $balance->available_quantity + (float) $balance->reserved_quantity,
            'unit_price' => (float) $balance->unit_price,
            'last_movement_at' => $balance->last_movement_at?->toDateTimeString(),
        ];
    }
}
