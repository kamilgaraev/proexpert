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
        // Получаем id текущей организации из запроса (через middleware organization.context)
        $organizationId = $request->attributes->get('current_organization_id');
        $roleSlug = null;
        if ($organizationId && $this->relationLoaded('roles')) {
            // Получаем все роли пользователя в этой организации
            $role = $this->roles->where('pivot.organization_id', $organizationId)
                ->first(fn($role) => in_array($role->slug, \App\Models\User::ADMIN_PANEL_ACCESS_ROLES));
            $roleSlug = $role?->slug;
        }
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role_slug' => $roleSlug,
            // Важно: isActiveInOrg может быть нерелевантно для пользователя админ-панели
            // Возможно, нужно поле is_active из самой модели User?
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Можно добавить другие поля при необходимости
        ];
    }
} 