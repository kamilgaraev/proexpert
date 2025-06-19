<?php

namespace App\Http\Resources\Api\V1\Admin\CompletedWork;

use Illuminate\Http\Resources\Json\JsonResource;

class CompletedWorkMaterialResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'material_id' => $this->id,
            'material_name' => $this->name,
            'measurement_unit' => $this->measurementUnit?->short_name,
            'quantity' => (float)$this->pivot->quantity,
            'unit_price' => isset($this->pivot->unit_price) ? (float)$this->pivot->unit_price : null,
            'total_amount' => isset($this->pivot->total_amount) ? (float)$this->pivot->total_amount : null,
            'notes' => $this->pivot->notes,
            'created_at' => $this->pivot->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->pivot->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
} 