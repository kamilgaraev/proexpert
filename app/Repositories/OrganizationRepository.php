<?php

namespace App\Repositories;

use App\Models\Organization;

class OrganizationRepository extends BaseRepository implements OrganizationRepositoryInterface
{
    public function __construct(Organization $model)
    {
        parent::__construct($model);
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