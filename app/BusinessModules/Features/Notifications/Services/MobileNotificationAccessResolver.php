<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;

final class MobileNotificationAccessResolver
{
    public function __construct(private readonly RoleScanner $roles) {}

    public function canAccess(User $user, int $organizationId, ?int $projectId = null): bool
    {
        if (! $user->organizations()
            ->where('organizations.id', $organizationId)
            ->wherePivot('is_active', true)
            ->exists()) {
            return false;
        }

        $context = $projectId === null
            ? AuthorizationContext::findOrganizationContext($organizationId)
            : AuthorizationContext::findProjectContext($projectId, $organizationId);

        if (! $context instanceof AuthorizationContext) {
            return false;
        }

        $contextIds = [];

        while ($context instanceof AuthorizationContext) {
            $contextIds[] = (int) $context->id;
            $context = $context->parentContext;
        }

        return $user->roleAssignments()
            ->active()
            ->whereIn('context_id', $contextIds)
            ->with('customRole')
            ->get()
            ->contains(function (UserRoleAssignment $assignment): bool {
                $interfaces = $assignment->role_type === UserRoleAssignment::TYPE_CUSTOM
                    ? ($assignment->customRole?->interface_access ?? [])
                    : $this->roles->getInterfaceAccess($assignment->role_slug);

                return in_array('mobile', $interfaces, true);
            });
    }
}
