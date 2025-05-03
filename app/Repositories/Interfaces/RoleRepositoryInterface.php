<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;

interface RoleRepositoryInterface extends RepositoryInterface
{
    /**
     * Найти роль по имени
     *
     * @param string $roleName
     * @return \App\Models\Role|null
     */
    public function findByName(string $roleName);

    /**
     * Найти роль по системному имени (slug).
     *
     * @param string $slug
     * @return \App\Models\Role|null
     */
    public function findBySlug(string $slug): ?\App\Models\Role;
} 