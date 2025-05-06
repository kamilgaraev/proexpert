<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    // ... другие методы ...

    /**
     * Найти пользователя по ID вместе с его ролями.
     *
     * @param int $id
     * @return User|null
     */
    public function findWithRoles(int $id): ?User;

    public function findByRoleInOrganization(int $organizationId, string $roleSlug): Collection;
    public function findByRoleInOrganizationPaginated(int $organizationId, string $roleSlug, int $perPage = 15): \Illuminate\Pagination\LengthAwarePaginator;
    public function attachToOrganization(int $userId, int $organizationId): void;
    public function assignRole(int $userId, int $roleId, int $organizationId): void;
    public function revokeRole(int $userId, int $roleId, int $organizationId): bool;
    public function detachFromOrganization(int $userId, int $organizationId): bool;
    public function findByEmail(string $email): ?\App\Models\User;

    /**
     * Check if a user has a specific role within a specific organization.
     *
     * @param int $userId
     * @param int $roleId
     * @param int $organizationId
     * @return bool
     */
    public function hasRoleInOrganization(int $userId, int $roleId, int $organizationId): bool;

    /**
     * Получить пагинированный список пользователей по роли в организации.
     */
    public function paginateByRoleInOrganization(
        string $roleSlug,
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator;

    /**
     * Получить данные по активности прорабов (из логов).
     *
     * @param int $organizationId
     * @param array $filters (project_id, user_id, date_from, date_to)
     * @return Collection
     */
    public function getForemanActivity(int $organizationId, array $filters = []): Collection;

    /**
     * Найти пользователей с одной из указанных ролей в организации.
     *
     * @param int $organizationId
     * @param array<string> $roleSlugs Массив слагов ролей
     * @return Collection
     */
    public function findByRolesInOrganization(int $organizationId, array $roleSlugs): Collection;
}