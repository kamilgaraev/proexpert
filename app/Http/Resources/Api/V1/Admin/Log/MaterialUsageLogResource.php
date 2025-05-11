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
            'organization_id' => $this->resource->organization_id,
            'project_id' => $this->resource->project_id,
            'project_name' => $this->whenLoaded('project', fn() => $this->resource->project?->name),
            'material_id' => $this->resource->material_id,
            'material_name' => $this->whenLoaded('material', fn() => $this->resource->material?->name),
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->resource->user?->name),
            'operation_type' => $this->resource->operation_type,
            'quantity' => (float) $this->resource->quantity,
            'unit_symbol' => $this->whenLoaded('material', fn() => $this->resource->material?->measurementUnit?->symbol),
            'unit_price' => $this->resource->unit_price ? (float) $this->resource->unit_price : null,
            'total_price' => $this->resource->total_price ? (float) $this->resource->total_price : null,
            'supplier_id' => $this->resource->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn() => $this->resource->supplier?->name),
            'document_number' => $this->resource->document_number,
            'invoice_date' => $this->resource->invoice_date,
            'work_type_id' => $this->resource->work_type_id,
            'work_type_name' => $this->whenLoaded('workType', fn() => $this->resource->workType?->name),
            'usage_date' => $this->resource->usage_date,
            'notes' => $this->resource->notes,
            'photo_url' => $this->resource->photo_url,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
} 