<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Interfaces\RoleRepositoryInterface;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    /**
     * RoleRepository constructor.
     */
    public function __construct()
    {
        parent::__construct(Role::class); // Передаем имя класса
    }

    /**
     * Найти роль по имени
     *
     * @param string $roleName
     * @return \App\Models\Role|null
     */
    public function findByName(string $roleName)
    {
        return $this->findOneBy('name', $roleName);
    }

    /**
     * Найти роль по системному имени (slug).
     *
     * @param string $slug
     * @return \App\Models\Role|null
     */
    public function findBySlug(string $slug): ?\App\Models\Role
    {
        // Используем $this->model из BaseRepository
        return $this->model->where('slug', $slug)->first();
    }
} 