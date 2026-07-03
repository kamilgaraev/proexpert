<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Helpers\AdminPanelAccessHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        $roleItems = [];

        if ($organizationId) {
            // Используем новую систему авторизации для получения ролей
            try {
                $authService = app(AuthorizationService::class);
                $adminPanelHelper = app(AdminPanelAccessHelper::class);
                $roleScanner = app(RoleScanner::class);
                $rolePayloadFormatter = app(RolePayloadFormatter::class);
                $context = AuthorizationContext::getOrganizationContext((int) $organizationId);
                $assignments = $authService->getUserRoles($this->resource, $context);
                $customRoles = OrganizationCustomRole::query()
                    ->where('organization_id', (int) $organizationId)
                    ->whereIn(
                        'slug',
                        $assignments
                            ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                            ->pluck('role_slug')
                            ->unique()
                            ->values()
                            ->all()
                    )
                    ->get()
                    ->keyBy('slug');

                $roleItems = $assignments
                    ->values()
                    ->map(function (UserRoleAssignment $assignment, int $index) use ($customRoles, $roleScanner, $rolePayloadFormatter): array {
                        $roleSlug = (string) $assignment->role_slug;

                        if ($assignment->role_type === UserRoleAssignment::TYPE_CUSTOM) {
                            $customRole = $customRoles->get($roleSlug);

                            return [
                                'id' => $customRole?->id ?? (0 - $index - 1),
                                'name' => $customRole?->name ?? $roleSlug,
                                'slug' => $roleSlug,
                                'type' => UserRoleAssignment::TYPE_CUSTOM,
                            ];
                        }

                        $roleData = $roleScanner->getRole($roleSlug) ?? ['name' => $roleSlug];

                        return [
                            'id' => 0 - $index - 1,
                            'name' => $rolePayloadFormatter->translatedRoleName($roleSlug, $roleData),
                            'slug' => $roleSlug,
                            'type' => UserRoleAssignment::TYPE_SYSTEM,
                        ];
                    })
                    ->unique('slug')
                    ->values()
                    ->all();
                
                // Ищем первую роль, которая дает доступ к админ панели
                $adminPanelRoles = $adminPanelHelper->getAdminPanelRoles($organizationId);
                foreach ($roleItems as $role) {
                    if (in_array($role['slug'], $adminPanelRoles, true)) {
                        $roleSlug = $role['slug'];
                        break;
                    }
                }
            } catch (\Exception $e) {
                // Таблицы новой системы еще не готовы
            }
        }
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'role_slug' => $roleSlug,
            'roles' => $roleItems,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
