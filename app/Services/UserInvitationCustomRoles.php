<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Collection;

final class UserInvitationCustomRoles
{
    public function resolve(int $organizationId, array $roleIds): Collection
    {
        if ($organizationId <= 0 || $roleIds === []) {
            return collect();
        }

        $ids = collect($roleIds)
            ->filter(static fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values();

        if ($ids->count() !== count($roleIds)) {
            throw new BusinessLogicException(trans_message('user_invitations.errors.invalid_roles'));
        }

        $roles = OrganizationCustomRole::query()
            ->forOrganization($organizationId)
            ->active()
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->get(['id', 'slug', 'name']);

        if ($roles->count() !== $ids->count()) {
            throw new BusinessLogicException(trans_message('user_invitations.errors.invalid_roles'));
        }

        return $roles->map(static fn (OrganizationCustomRole $role): array => [
            'id' => $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
        ]);
    }
}
