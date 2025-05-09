<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\ReportTemplate) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'report_type' => $this->resource->report_type,
            'organization_id' => $this->resource->organization_id,
            'user_id' => $this->resource->user_id,
            'columns_config' => $this->resource->columns_config, // Отдаем как есть (массив объектов)
            'is_default' => (bool) $this->resource->is_default,
            'created_at' => $this->resource->created_at->toISOString(),
            'updated_at' => $this->resource->updated_at->toISOString(),
            // Можно добавить информацию о пользователе-создателе, если нужно
            // 'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
} 