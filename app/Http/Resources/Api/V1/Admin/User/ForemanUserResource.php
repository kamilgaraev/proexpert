<?php

namespace App\Http\Resources\Api\V1\Admin\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForemanUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Ensure we have a User model instance
        if (!$this->resource instanceof \App\Models\User) {
            return [];
        }

        // Убираем сложную логику isActiveInOrg, т.к. is_active глобальное поле
        /*
        $organizationId = $request->attributes->get('organization_id');
        $isActiveInOrg = false;

        // Check if the user is active within the context of the current organization
        // This requires accessing the pivot data.
        if ($organizationId && $this->resource->relationLoaded('organizations')) {
             $orgPivot = $this->resource->organizations->firstWhere('id', $organizationId);
             if ($orgPivot && $orgPivot->pivot) {
                 $isActiveInOrg = (bool) $orgPivot->pivot->is_active;
             }
        } else {
             // Fallback or additional logic if organizations relation isn't loaded
             // or organization context is missing. Might need a direct check.
             // For simplicity, assume false if context is missing.
             // Consider logging a warning if org context is expected but missing.
        }
        */

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone ?? null, // Добавляем телефон
            'position' => $this->resource->position ?? null, // Добавляем должность
            'avatar_url' => $this->resource->avatar_url, // Добавляем URL аватара
            'is_active' => (bool) $this->resource->is_active, // Возвращаем глобальный статус
            // 'isActiveInOrg' => $isActiveInOrg, // Убрали
            'createdAt' => $this->resource->created_at,
            'updatedAt' => $this->resource->updated_at,
        ];
    }
} 