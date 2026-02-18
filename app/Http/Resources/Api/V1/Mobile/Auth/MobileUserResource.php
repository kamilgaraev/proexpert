<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Mobile\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;

class MobileUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $user */
        $user = $this->resource;
        
        $authService = app(AuthorizationService::class);
        $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);

        $context = $user->current_organization_id 
            ? AuthorizationContext::getOrganizationContext($user->current_organization_id)
            : null;

        // Получаем структурированные права
        $permissions = $authService->getUserPermissionsStructured($user, $context);
        $roles = $authService->getUserRoles($user, $context);
        
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'position' => $user->position,
            'avatar_url' => $user->avatar_url,
            'current_organization_id' => $user->current_organization_id,
            'has_completed_onboarding' => $user->has_completed_onboarding,
            'auth' => [
                'roles' => $roles->pluck('role_slug'),
                'role_labels' => $roles->map(fn($r) => $roleScanner->getRole($r->role_slug)['name'] ?? $r->role_slug),
                'permissions' => $permissions['system'] ?? [],
                'modules' => $permissions['modules'] ?? [],
            ],
            // Список доступных организаций для быстрого переключения
            'organizations' => $user->organizations->map(fn($org) => [
                'id' => $org->id,
                'name' => $org->name,
                'is_active' => (bool)$org->pivot->is_active,
            ]),
        ];
    }
}
