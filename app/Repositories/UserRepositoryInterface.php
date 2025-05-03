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
} 