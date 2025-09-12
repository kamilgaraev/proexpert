<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\Models\User;
use App\Models\Organization;
use App\Services\Landing\MultiOrganizationService;

class MultiOrganizationHelperService
{
    protected MultiOrganizationService $multiOrgService;

    public function __construct(MultiOrganizationService $multiOrgService)
    {
        $this->multiOrgService = $multiOrgService;
    }

    public function hasModuleAccess(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        // Используем новую модульную систему для проверки доступа
        return hasModuleAccess('multi-organization', $user);
    }

    public function isHoldingOrganization(int $organizationId): bool
    {
        $org = Organization::find($organizationId);
        return $org ? $org->is_holding : false;
    }

    public function getAccessibleOrganizations(User $user): array
    {
        return $this->multiOrgService->getAccessibleOrganizations($user)->toArray();
    }

    public function canCreateHolding(User $user): bool
    {
        $organization = $user->currentOrganization;
        
        if (!$organization) {
            return false;
        }

        // Проверяем что организация не является дочерней и не является уже холдингом
        return !$organization->parent_organization_id && !$organization->is_holding;
    }

    public function getUserOrganizationRole(User $user, int $organizationId): ?string
    {
        $pivot = $user->organizations()->where('organizations.id', $organizationId)->first()?->pivot;
        
        if (!$pivot) {
            return null;
        }
        
        if ($pivot->is_owner) {
            return 'organization_owner';
        }
        
        // Можно расширить логику для определения более детальных ролей
        $settings = json_decode($pivot->settings, true) ?? [];
        return $settings['role'] ?? 'member';
    }

    public function hasAccessToOrganization(User $user, int $targetOrgId, string $permission = 'read'): bool
    {
        return $this->multiOrgService->hasAccessToOrganization($user, $targetOrgId, $permission);
    }

    public function getOrganizationHierarchy(int $organizationId): array
    {
        return $this->multiOrgService->getOrganizationHierarchy($organizationId);
    }

    public function getOrganizationData(int $organizationId, User $user): array
    {
        return $this->multiOrgService->getOrganizationData($organizationId, $user);
    }

    public function canManageChildOrganizations(User $user): bool
    {
        if (!$this->hasModuleAccess($user)) {
            return false;
        }

        return hasModulePermission('multi_organization.manage_children', $user);
    }

    public function canAddChildOrganization(User $user): bool
    {
        if (!$this->hasModuleAccess($user)) {
            return false;
        }

        return hasModulePermission('multi_organization.add_child_organization', $user);
    }

    public function canManageChildUsers(User $user): bool
    {
        if (!$this->hasModuleAccess($user)) {
            return false;
        }

        return hasModulePermission('multi_organization.manage_child_users', $user);
    }

    public function getModuleSettings(int $organizationId): array
    {
        $module = app(\App\BusinessModules\Core\MultiOrganization\MultiOrganizationModule::class);
        
        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', 'multi-organization');
            })
            ->first();

        if (!$activation) {
            return $module->getDefaultSettings();
        }

        return array_merge(
            $module->getDefaultSettings(),
            $activation->module_settings ?? []
        );
    }

    public function updateModuleSettings(int $organizationId, array $settings): void
    {
        $module = app(\App\BusinessModules\Core\MultiOrganization\MultiOrganizationModule::class);
        
        if (!$module->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля');
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', 'multi-organization');
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings)
            ]);
        }
    }
}
