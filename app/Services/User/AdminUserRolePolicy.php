<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Domain\Authorization\Services\AuthorizationService;
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

        return $this->roleScanner->getAllRoles()
            ->filter(fn (array $role): bool => $this->rolePayloadFormatter->isAssignableSystemRole($role))
            ->filter(
                fn (array $role, string $roleSlug): bool => collect($managerRoleSlugs)
                    ->contains(fn (string $managerRoleSlug): bool => $this->roleScanner->canManageRole(
                        $managerRoleSlug,
                        $roleSlug
                    ))
            )
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

    public function assertCanAssign(User $actor, int $organizationId, string $roleSlug): void
    {
        if (! in_array($roleSlug, $this->assignableRoleSlugsFor($actor, $organizationId), true)) {
            throw new BusinessLogicException(trans_message('user.role_assignment_forbidden'), 422);
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
        $roleSlugs = $this->authorizationService->getUserRoleSlugs($actor, [
            'organization_id' => $organizationId,
        ]);

        if ($actor->isSystemAdmin()) {
            $roleSlugs[] = 'super_admin';
        }

        return array_values(array_unique(array_filter($roleSlugs, 'is_string')));
    }
}
