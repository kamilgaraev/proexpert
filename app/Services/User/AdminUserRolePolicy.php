<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Exceptions\BusinessLogicException;
use App\Models\User;

final class AdminUserRolePolicy
{
    public function __construct(
        private readonly RoleScanner $roleScanner,
        private readonly RolePayloadFormatter $rolePayloadFormatter,
        private readonly AuthorizationService $authorizationService
    ) {}

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    public function optionsFor(User $actor, int $organizationId): array
    {
        $managerRoleSlugs = $this->managerRoleSlugs($actor, $organizationId);

        $roles = $this->roleScanner->getAllRoles()
            ->filter(fn (array $role): bool => $this->rolePayloadFormatter->isAssignableSystemRole($role))
            ->filter(
                fn (array $role, string $roleSlug): bool => collect($managerRoleSlugs)
                    ->contains(fn (string $managerRoleSlug): bool => $this->roleScanner->canManageRole(
                        $managerRoleSlug,
                        $roleSlug
                    ))
            );
        $interfaces = $roles->keys()->flatMap(
            fn (string $roleSlug): array => $this->roleScanner->getInterfaceAccess($roleSlug)
        )->unique()->values()->all();
        $actorInterfaces = $this->delegableInterfaces($actor, $organizationId, $interfaces);

        return $roles
            ->filter(fn (array $role, string $roleSlug): bool => array_diff(
                $this->roleScanner->getInterfaceAccess($roleSlug), $actorInterfaces
            ) === [])
            ->map(fn (array $role, string $roleSlug): array => [
                'slug' => $roleSlug,
                'name' => $this->rolePayloadFormatter->translatedRoleName($roleSlug, $role),
            ])
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function assignableRoleSlugsFor(User $actor, int $organizationId): array
    {
        return array_column($this->optionsFor($actor, $organizationId), 'slug');
    }

    public function assertCanAssign(
        User $actor,
        int $organizationId,
        string $roleSlug,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM
    ): void
    {
        if ($roleType === UserRoleAssignment::TYPE_CUSTOM) {
            $role = OrganizationCustomRole::query()->where('organization_id', $organizationId)
                ->where('slug', $roleSlug)->where('is_active', true)->first();
            if (! $role) {
                throw new BusinessLogicException(trans_message('user.role_assignment_forbidden'), 422);
            }

            $interfaces = $role->interface_access ?? [];
            if (array_diff($interfaces, $this->delegableInterfaces($actor, $organizationId, $interfaces)) !== []) {
                throw new BusinessLogicException(trans_message('user.role_assignment_forbidden'), 422);
            }

            $authorization = $this->authorizationService->forCurrentChecks(true);
            $resolver = app(PermissionResolver::class)->forCurrentChecks();
            foreach ($resolver->getCustomRolePermissions($roleSlug, $organizationId) as $permission) {
                if (! $authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
                    throw new BusinessLogicException(trans_message('user.role_assignment_forbidden'), 422);
                }
            }

            return;
        }

        if (! in_array($roleSlug, $this->assignableRoleSlugsFor($actor, $organizationId), true)) {
            throw new BusinessLogicException(trans_message('user.role_assignment_forbidden'), 422);
        }
    }

    public function assertCanManageUser(User $actor, User $target, int $organizationId, bool $changesGlobalIdentity = false): void
    {
        if ((int) $actor->id === (int) $target->id || $this->isSystemAdministrator($actor)) {
            return;
        }

        $systemAssignment = $target->roleAssignments()->active()
            ->where('role_type', UserRoleAssignment::TYPE_SYSTEM)
            ->whereHas('context', static fn ($query) => $query->where('type', AuthorizationContext::TYPE_SYSTEM))
            ->exists();
        $sharedIdentity = $changesGlobalIdentity && $target->organizations()
            ->where('organizations.id', '<>', $organizationId)->wherePivot('is_active', true)->exists();

        if ($systemAssignment || $sharedIdentity) {
            throw new BusinessLogicException(trans_message('user.user_management_forbidden'), 403);
        }

        $assignableRoles = $this->assignableRoleSlugsFor($actor, $organizationId);
        $assignments = $target->roleAssignments()->active()->with('customRole')
            ->whereHas('context', static function ($query) use ($organizationId): void {
                $query->where(static fn ($organization) => $organization
                    ->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $organizationId))
                    ->orWhere(static fn ($project) => $project->where('type', AuthorizationContext::TYPE_PROJECT)
                        ->whereHas('parentContext', static fn ($parent) => $parent
                            ->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $organizationId)));
            })->get();
        $authorization = $this->authorizationService->forCurrentChecks(true);
        $resolver = app(PermissionResolver::class)->forCurrentChecks();

        foreach ($assignments as $assignment) {
            if ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM
                && ! in_array($assignment->role_slug, $assignableRoles, true)) {
                throw new BusinessLogicException(trans_message('user.user_management_forbidden'), 403);
            }

            if ($assignment->role_type !== UserRoleAssignment::TYPE_CUSTOM || ! $assignment->customRole) {
                continue;
            }

            $interfaces = $assignment->customRole->interface_access ?? [];
            if (array_diff($interfaces, $this->delegableInterfaces($actor, $organizationId, $interfaces)) !== []) {
                throw new BusinessLogicException(trans_message('user.user_management_forbidden'), 403);
            }

            foreach ($resolver->getCustomRolePermissions($assignment->role_slug, $organizationId) as $permission) {
                if (! $authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
                    throw new BusinessLogicException(trans_message('user.user_management_forbidden'), 403);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $roleSlugs
     * @return array<int, string>
     */
    public function labelsFor(array $roleSlugs): array
    {
        return array_map(fn (string $roleSlug): string => $this->labelFor($roleSlug), $roleSlugs);
    }

    public function primaryRolePriorities(): array
    {
        return $this->roleScanner->getAllRoles()->map(static function (array $role): int {
            $priority = $role['primary_priority'] ?? null;

            return is_int($priority) && $priority >= 0 && $priority < 100000 ? $priority : 100000;
        })->all();
    }

    public function presentOrganizationRoles(User $target, int $organizationId): array
    {
        $empty = ['roles' => [], 'role_labels' => [], 'primary_role' => null, 'primary_role_label' => $this->labelFor(null)];
        if ($organizationId < 1) {
            return $empty;
        }
        $assignments = $target->relationLoaded('roleAssignments') ? $target->roleAssignments
            : $target->roleAssignments()->availableInOrganization($organizationId)->with(['context', 'customRole'])->get();
        $assignments->loadMissing(['context', 'customRole']);
        $priorities = $this->primaryRolePriorities();
        $resolved = [];
        foreach ($assignments as $assignment) {
            if (! $assignment instanceof UserRoleAssignment || ! $assignment->is_active
                || ($assignment->expires_at !== null && $assignment->expires_at->lessThanOrEqualTo(now()))) {
                continue;
            }
            $context = $assignment->context;
            if (! $context instanceof AuthorizationContext || $context->type !== AuthorizationContext::TYPE_ORGANIZATION
                || (int) $context->resource_id !== $organizationId) {
                continue;
            }
            if ($assignment->role_type === UserRoleAssignment::TYPE_CUSTOM) {
                $role = $assignment->customRole;
                if (! $role instanceof OrganizationCustomRole || ! $role->is_active || (int) $role->id !== (int) $assignment->custom_role_id
                    || (int) $role->organization_id !== $organizationId || $role->slug !== $assignment->role_slug) {
                    continue;
                }
                $label = $role->name;
                $priority = 100000;
            } elseif ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM) {
                $label = $this->labelFor($assignment->role_slug);
                $priority = $priorities[$assignment->role_slug] ?? 100000;
            } else {
                continue;
            }
            $identity = $assignment->role_type.':'.$assignment->custom_role_id.':'.$assignment->role_slug;
            $candidate = ['slug' => $assignment->role_slug, 'label' => $label, 'priority' => $priority, 'id' => (int) $assignment->id];
            if (! isset($resolved[$identity]) || $candidate['id'] < $resolved[$identity]['id']) {
                $resolved[$identity] = $candidate;
            }
        }
        if ($resolved === []) {
            return $empty;
        }
        $primary = collect($resolved)->sort(static fn (array $left, array $right): int => [$left['priority'], $left['id']] <=> [$right['priority'], $right['id']])->first();

        return ['roles' => array_values(array_unique(array_column($resolved, 'slug'))),
            'role_labels' => array_values(array_column($resolved, 'label')), 'primary_role' => $primary['slug'], 'primary_role_label' => $primary['label']];
    }

    public function labelFor(?string $roleSlug): string
    {
        if (! is_string($roleSlug) || $roleSlug === '') {
            return trans_message('user.role_missing');
        }

        $role = $this->roleScanner->getRole($roleSlug);

        if (! is_array($role)) {
            return trans_message('user.role_unknown');
        }

        return $this->rolePayloadFormatter->translatedRoleName($roleSlug, $role);
    }

    /**
     * @return array<int, string>
     */
    private function managerRoleSlugs(User $actor, int $organizationId): array
    {
        $authorization = $this->authorizationService->forCurrentChecks(true);
        $context = AuthorizationContext::findOrganizationContext($organizationId);
        $roleSlugs = $context ? $authorization->getUserRoles($actor, $context)
            ->where('role_type', UserRoleAssignment::TYPE_SYSTEM)->pluck('role_slug')->all() : [];

        if ($authorization->canCurrent($actor, 'users.roles', ['organization_id' => $organizationId])) {
            $roleSlugs[] = 'organization_admin';
        }

        if ($this->isSystemAdministrator($actor)) {
            $roleSlugs[] = 'super_admin';
        }

        return array_values(array_unique(array_filter($roleSlugs, 'is_string')));
    }

    private function delegableInterfaces(User $actor, int $organizationId, array $interfaces): array
    {
        if ($this->isSystemAdministrator($actor)) {
            return $interfaces;
        }

        $context = AuthorizationContext::findOrganizationContext($organizationId);
        if (! $context) {
            return [];
        }
        $authorization = $this->authorizationService->forCurrentChecks(true);

        return collect($interfaces)->unique()->filter(
            static fn ($interface): bool => is_string($interface)
                && $authorization->canAccessInterface($actor, $interface, $context)
        )->values()->all();
    }

    private function isSystemAdministrator(User $actor): bool
    {
        $authorization = $this->authorizationService->forCurrentChecks(true);
        $context = AuthorizationContext::getSystemContext();

        return $authorization->hasRole($actor, 'super_admin', $context->id)
            || $authorization->hasRole($actor, 'system_admin', $context->id);
    }
}
