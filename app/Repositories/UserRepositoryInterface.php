<?php

namespace App\Repositories;

use App\Models\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email);
    
    public function findWithRoles(int $id);
    
    public function getUsersInOrganization(int $organizationId);
    
    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false, bool $isActive = true);
    
    public function assignRole(int $userId, int $roleId, ?int $organizationId = null);

    public function findByRoleInOrganization(int $organizationId, string $roleName);

    /**
     * Отозвать роль у пользователя в рамках организации.
     *
     * @param int $userId ID пользователя.
     * @param int $roleId ID роли.
     * @param int $organizationId ID организации.
     * @return bool True если роль была отозвана, false если связь не найдена.
     */
    public function revokeRole(int $userId, int $roleId, int $organizationId): bool;

    /**
     * Отсоединить пользователя от организации.
     *
     * @param int $userId ID пользователя.
     * @param int $organizationId ID организации.
     * @return bool True если пользователь был отсоединен, false если связь не найдена.
     */
    public function detachFromOrganization(int $userId, int $organizationId): bool;
} 