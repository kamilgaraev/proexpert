<?php

namespace App\Helpers;

use App\Models\User;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Services\MultiOrganizationHelperService;

/**
 * @deprecated Используйте MultiOrganizationHelperService вместо статических методов
 * Этот класс сохранен для обратной совместимости
 */
class MultiOrganizationHelper
{
    public static function hasModuleAccess(User $user): bool
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->hasModuleAccess($user);
    }

    public static function isHoldingOrganization(int $organizationId): bool
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->isHoldingOrganization($organizationId);
    }

    public static function getAccessibleOrganizations(User $user): array
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->getAccessibleOrganizations($user);
    }

    public static function canCreateHolding(User $user): bool
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->canCreateHolding($user);
    }

    public static function getUserOrganizationRole(User $user, int $organizationId): ?string
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->getUserOrganizationRole($user, $organizationId);
    }

    // Дополнительные методы для совместимости
    public static function hasAccessToOrganization(User $user, int $targetOrgId, string $permission = 'read'): bool
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->hasAccessToOrganization($user, $targetOrgId, $permission);
    }

    public static function getOrganizationHierarchy(int $organizationId): array
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->getOrganizationHierarchy($organizationId);
    }

    public static function canManageChildOrganizations(User $user): bool
    {
        $helperService = app(MultiOrganizationHelperService::class);
        return $helperService->canManageChildOrganizations($user);
    }
} 