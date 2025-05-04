<?php

namespace App\Http\Resources\Api\V1\Admin\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\User\ForemanUserResource; // Импортируем ресурс прораба

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Убедимся, что ресурс - модель Project
        if (!$this->resource instanceof \App\Models\Project) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'address' => $this->resource->address,
            'description' => $this->resource->description,
            'status' => $this->resource->status,
            'start_date' => $this->resource->start_date?->toDateString(), // Форматируем дату
            'end_date' => $this->resource->end_date?->toDateString(), // Форматируем дату
            'is_archived' => (bool) $this->resource->is_archived,
            'additional_info' => $this->resource->additional_info,
            'organization_id' => $this->resource->organization_id,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            
            // Включаем назначенных пользователей (прорабов), если они были загружены
            'assigned_users' => ForemanUserResource::collection($this->whenLoaded('users')),
            
            // Можно добавить количество назначенных пользователей для списка
            'assigned_users_count' => $this->whenCounted('users', $this->resource->users_count), // users_count должен быть загружен через withCount
        ];
    }
} 