<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Organization;

interface OrganizationRepositoryInterface extends RepositoryInterface
{
    /**
     * Найти организацию вместе с пользователями.
     *
     * @param int $id
     * @return Organization|null
     */
    public function findWithUsers(int $id);
    
    /**
     * Получить организации, к которым принадлежит пользователь.
     *
     * @param int $userId
     * @return Collection
     */
    public function getOrganizationsForUser(int $userId);
} 