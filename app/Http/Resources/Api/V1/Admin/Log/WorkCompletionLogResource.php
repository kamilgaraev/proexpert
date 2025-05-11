<?php

namespace App\Http\Resources\Api\V1\Admin\Log;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkCompletionLogResource extends JsonResource
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
            'organization_id' => $this->resource->organization_id,
            'project_id' => $this->resource->project_id,
            'project_name' => $this->whenLoaded('project', fn() => $this->resource->project?->name),
            'work_type_id' => $this->resource->work_type_id,
            'work_type_name' => $this->whenLoaded('workType', fn() => $this->resource->workType?->name),
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->resource->user?->name),
            'quantity' => $this->resource->quantity ? (float) $this->resource->quantity : null,
            'unit_symbol' => $this->whenLoaded('workType', fn() => $this->resource->workType?->measurementUnit?->short_name),
            'unit_price' => $this->resource->unit_price ? (float) $this->resource->unit_price : null,
            'total_price' => $this->resource->total_price ? (float) $this->resource->total_price : null,
            'completion_date' => $this->resource->completion_date,
            'performers_description' => $this->resource->performers_description,
            'notes' => $this->resource->notes,
            'photo_url' => $this->resource->photo_url,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
} 