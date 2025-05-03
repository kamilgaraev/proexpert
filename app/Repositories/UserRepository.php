<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }

    public function findWithRoles(int $id)
    {
        return $this->model->with(['roles', 'organizations'])->find($id);
    }

    public function getUsersInOrganization(int $organizationId)
    {
        return $this->model->whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->get();
    }

    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false, bool $isActive = true)
    {
        $user = $this->find($userId);
        
        if (!$user) {
            return false;
        }
        
        $user->organizations()->attach($organizationId, [
            'is_owner' => $isOwner,
            'is_active' => $isActive
        ]);
        
        return $user;
    }

    public function assignRole(int $userId, int $roleId, ?int $organizationId = null)
    {
        $user = $this->find($userId);
        
        if (!$user) {
            return false;
        }
        
        $pivotData = $organizationId ? ['organization_id' => $organizationId] : [];
        $user->roles()->attach($roleId, $pivotData);
        
        return $user;
    }
} 