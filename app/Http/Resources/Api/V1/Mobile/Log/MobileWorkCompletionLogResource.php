<?php

namespace App\Http\Resources\Api\V1\Mobile\Log;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileWorkCompletionLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\Models\Log\WorkCompletionLog) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'project_id' => $this->resource->project_id,
            'project_name' => $this->whenLoaded('project', fn() => $this->resource->project?->name),
            'work_type_id' => $this->resource->work_type_id,
            'work_type_name' => $this->whenLoaded('workType', fn() => $this->resource->workType?->name),
            'measurement_unit_symbol' => $this->whenLoaded('workType', fn() => $this->resource->workType?->measurementUnit?->symbol),
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->resource->user?->name),
            
            'quantity' => (float) $this->resource->quantity,
            'completion_date' => $this->resource->completion_date->format('Y-m-d'),
            'performers_description' => $this->resource->performers_description,
            'photo_url' => $this->resource->photo_url, // Используем аксессор из модели
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at->toISOString(),
            'updated_at' => $this->resource->updated_at->toISOString(),
        ];
    }
} 