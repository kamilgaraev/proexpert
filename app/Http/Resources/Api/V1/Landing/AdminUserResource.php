<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Соответствует схеме AdminUser в openapi.yaml
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Получаем роль через новую систему авторизации
            'role_slug' => $this->getUserRoleSlug($request),
            // Поле isActiveInOrg нужно вычислить или получить из pivot-таблицы
            // Пример: 'isActiveInOrg' => $this->relationLoaded('organizations') ? $this->organizations->first()?->pivot?->is_active : false,
            // Или если есть атрибут в модели User:
            // 'isActiveInOrg' => $this->is_active_in_current_org, 
            'isActiveInOrg' => true, // ЗАГЛУШКА! Замените реальной логикой
            'created_at' => $this->created_at?->toISOString(), // Добавляем ? для null safety
            'updated_at' => $this->updated_at?->toISOString(), // Добавляем ? для null safety
        ];
    }

    /**
     * Получить первую роль пользователя в организации через новую систему авторизации
     */
    private function getUserRoleSlug(Request $request): ?string
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId) {
            return null;
        }

        try {
            $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            $roles = $authService->getUserRoleSlugs($this, ['organization_id' => $organizationId]);
            
            return $roles[0] ?? null; // Возвращаем первую роль
        } catch (\Exception $e) {
            // Таблицы новой системы еще не готовы
            return null;
        }
    }
} 