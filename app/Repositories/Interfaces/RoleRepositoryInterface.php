<?php

namespace App\Repositories\Interfaces;

// use App\Repositories\RepositoryInterface; // Старое наследование
use App\Repositories\Interfaces\BaseRepositoryInterface; // Новое наследование

// interface RoleRepositoryInterface extends RepositoryInterface // Старое наследование
interface RoleRepositoryInterface extends BaseRepositoryInterface // Новое наследование
{
    // Методы all, find, create, update, delete теперь будут наследоваться от BaseRepositoryInterface
    // findBy остается, так как его нет в BaseRepositoryInterface и он специфичен для старого интерфейса
    public function findBy(string $field, mixed $value, array $columns = ['*']); // Этот метод специфичен и может остаться или быть пересмотрен

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