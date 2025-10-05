<?php

namespace App\Domain\Authorization\Policies;

use App\Models\User;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\AuthorizationService;

class CustomRolePolicy
{
    protected AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    public function view(User $user, OrganizationCustomRole $role): bool
    {
        return $this->authService->can(
            $user,
            'roles.view_custom',
            ['organization_id' => $role->organization_id]
        );
    }

    public function update(User $user, OrganizationCustomRole $role): bool
    {
        return $this->authService->can(
            $user,
            'roles.manage_custom',
            ['organization_id' => $role->organization_id]
        );
    }

    public function delete(User $user, OrganizationCustomRole $role): bool
    {
        return $this->authService->can(
            $user,
            'roles.manage_custom',
            ['organization_id' => $role->organization_id]
        );
    }
}
