<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseMovementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse->name ?? null,
            'material_id' => $this->material_id,
            'material_name' => $this->material->name ?? null,
            'material_code' => $this->material->code ?? null,
            'quantity' => (float)$this->quantity,
            'price' => (float)$this->price,
            'total_value' => (float)($this->quantity * $this->price),
            'measurement_unit' => $this->material->measurementUnit->name ?? null,
            'project_id' => $this->project_id,
            'project_name' => $this->project->name ?? null,
            'user_id' => $this->user_id,
            'user_name' => $this->user->name ?? null,
            'document_number' => $this->document_number,
            'reason' => $this->reason,
            'movement_date' => $this->movement_date->toDateTimeString(),
            'metadata' => $this->metadata,
        ];
    }
}
