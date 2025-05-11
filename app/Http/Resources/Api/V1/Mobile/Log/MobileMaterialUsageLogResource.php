<?php

namespace App\Http\Resources\Api\V1\Mobile\Log;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileMaterialUsageLogResource extends JsonResource
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
            'measurement_unit_symbol' => $this->whenLoaded('material', fn() => $this->resource->material?->measurementUnit?->symbol),
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->resource->user?->name),
            
            'operation_type' => $this->resource->operation_type,
            'quantity' => (float) $this->resource->quantity,
            'usage_date' => $this->resource->usage_date->format('Y-m-d'), // Убедимся, что дата отформатирована
            
            // Поля для приемки (receipt)
            'supplier_id' => $this->when($this->resource->operation_type === 'receipt', fn() => $this->resource->supplier_id),
            'supplier_name' => $this->when($this->resource->operation_type === 'receipt' && $this->resource->relationLoaded('supplier'), fn() => $this->resource->supplier?->name),
            'invoice_number' => $this->when($this->resource->operation_type === 'receipt', fn() => $this->resource->invoice_number),
            'invoice_date' => $this->when($this->resource->operation_type === 'receipt' && $this->resource->invoice_date, fn() => $this->resource->invoice_date->format('Y-m-d')),
            
            // Поля для списания (write_off)
            'work_type_id' => $this->when($this->resource->operation_type === 'write_off', fn() => $this->resource->work_type_id),
            'work_type_name' => $this->when($this->resource->operation_type === 'write_off' && $this->resource->relationLoaded('workType'), fn() => $this->resource->workType?->name),

            'photo_url' => $this->resource->photo_url, // Используем аксессор из модели
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at->toISOString(),
            'updated_at' => $this->resource->updated_at->toISOString(),
        ];
    }
} 