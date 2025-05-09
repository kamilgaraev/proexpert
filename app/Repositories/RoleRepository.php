<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use Illuminate\Support\Collection;

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

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $id, array $columns = ['*']): ?Role
    {
        return parent::findById($id, $columns);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function delete(int $id): bool
    {
        return parent::deleteById($id);
    }
    // End of RepositoryInterface methods
} 