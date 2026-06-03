<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Resources;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseMovementResource extends JsonResource
{
    public function toArray($request): array
    {
        $movement = $this->resource;

        return [
            'id' => $this->id,
            'movement_id' => $this->id,
            'movement_type' => $this->movement_type,
            'operation_category' => $this->operation_category,
            'operation_category_label' => $movement instanceof WarehouseMovement
                ? $movement->operationCategoryLabel()
                : null,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse->name ?? null,
            'material_id' => $this->material_id,
            'material_name' => $this->material->name ?? null,
            'material_code' => $this->material->code ?? null,
            'project_material_delivery_id' => $this->project_material_delivery_id,
            'quantity' => (float)$this->quantity,
            'price' => (float)$this->price,
            'total_value' => (float)($this->quantity * $this->price),
            'measurement_unit' => $this->material->measurementUnit->name ?? null,
            'project_id' => $this->project_id,
            'project_name' => $this->project->name ?? null,
            'user_id' => $this->user_id,
            'user_name' => $this->user->name ?? null,
            'related_user_id' => $this->related_user_id,
            'related_user_name' => $this->relatedUser->name ?? null,
            'related_user' => $this->relatedUser ? [
                'id' => $this->relatedUser->id,
                'name' => $this->relatedUser->name,
                'email' => $this->relatedUser->email,
            ] : null,
            'document_number' => $this->document_number,
            'reason' => $this->reason,
            'movement_date' => $this->movement_date->toDateTimeString(),
            'metadata' => $this->metadata,
            'photo_gallery' => $this->photo_gallery,
        ];
    }
}
