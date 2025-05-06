<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * @mixin \App\Models\User
 */
class AdminPanelUserResource extends JsonResource
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
            // Используем whenLoaded для роли, которую загрузили в контроллере
            'role_slug' => $this->whenLoaded('roles', fn() => $this->roles->first()?->slug),
            // Важно: isActiveInOrg может быть нерелевантно для пользователя админ-панели
            // Возможно, нужно поле is_active из самой модели User?
            'is_active' => $this->is_active,
            'created_at' => ($this->created_at instanceof Carbon) ? $this->created_at->toISOString() : $this->created_at,
            'updated_at' => ($this->updated_at instanceof Carbon) ? $this->updated_at->toISOString() : $this->updated_at,
            // Можно добавить другие поля при необходимости
        ];
    }
} 