<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'avatar_url' => $this->avatar_url, // Кешированный accessor (55 минут)
            'is_active' => $this->is_active,
            // 'user_type' => $this->user_type, // Удалена в новой системе авторизации
            'current_organization_id' => $this->current_organization_id,
            'has_completed_onboarding' => $this->has_completed_onboarding,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Можно добавить другие необходимые поля или отношения
        ];
    }
} 