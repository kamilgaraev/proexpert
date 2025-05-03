<?php

namespace App\Repositories;

interface OrganizationRepositoryInterface extends RepositoryInterface
{
    public function findWithUsers(int $id);
    
    public function getOrganizationsForUser(int $userId);
} 