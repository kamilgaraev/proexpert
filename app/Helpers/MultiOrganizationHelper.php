<?php

namespace App\Helpers;

use App\Models\User;
use App\Models\Organization;
use App\Services\Landing\MultiOrganizationService;

class MultiOrganizationHelper
{
    public static function hasModuleAccess(User $user): bool
    {
        return hasModuleAccess('multi_organization', $user->current_organization_id);
    }

    public static function isHoldingOrganization(int $organizationId): bool
    {
        $org = Organization::find($organizationId);
        return $org ? $org->is_holding : false;
    }

    public static function getAccessibleOrganizations(User $user): array
    {
        $service = app(MultiOrganizationService::class);
        $organizations = $service->getAccessibleOrganizations($user);
        return $organizations->toArray();
    }

    public static function canCreateHolding(User $user): bool
    {
        $organization = $user->currentOrganization;
        return $organization && !$organization->is_holding;
    }

    public static function getUserOrganizationRole(User $user, int $organizationId): ?string
    {
        $pivot = $user->organizations()->where('organizations.id', $organizationId)->first()?->pivot;
        
        if (!$pivot) {
            return null;
        }
        
        if ($pivot->is_owner) {
            return 'organization_owner';
        }
        
        return 'member';
    }
} 