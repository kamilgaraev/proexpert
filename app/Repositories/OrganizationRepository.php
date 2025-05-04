<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;

class OrganizationRepository extends BaseRepository implements OrganizationRepositoryInterface
{
    /**
     * OrganizationRepository constructor.
     */
    public function __construct()
    {
        parent::__construct(Organization::class); // Передаем имя класса
    }

    public function findWithUsers(int $id)
    {
        return $this->model->with('users')->find($id);
    }

    public function getOrganizationsForUser(int $userId)
    {
        return $this->model->whereHas('users', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->get();
    }
} 