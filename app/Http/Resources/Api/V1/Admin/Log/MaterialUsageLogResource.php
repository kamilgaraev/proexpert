<?php

namespace App\Http\Resources\Api\V1\Admin\Log;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialUsageLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\Models\Log\MaterialUsageLog) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'project_id' => $this->resource->project_id,
            'project_name' => $this->whenLoaded('project', fn() => $this->resource->project?->name),
            'material_id' => $this->resource->material_id,
            'material_name' => $this->whenLoaded('material', fn() => $this->resource->material?->name),
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->resource->user?->name),
            'quantity' => (float) $this->resource->quantity,
            'unit_symbol' => $this->whenLoaded('material', fn() => $this->resource->material?->measurementUnit?->symbol),
            'usage_date' => $this->resource->usage_date,
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
} 