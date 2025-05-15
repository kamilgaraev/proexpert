<?php

namespace App\Repositories\Interfaces;

// use App\Repositories\RepositoryInterface; // Старое наследование
use App\Repositories\Interfaces\BaseRepositoryInterface; // Новое наследование
use Illuminate\Database\Eloquent\Collection; // В старом коде Illuminate\Support\Collection
use App\Models\Organization;

// interface OrganizationRepositoryInterface extends RepositoryInterface // Старое наследование
interface OrganizationRepositoryInterface extends BaseRepositoryInterface // Новое наследование
{
    // Методы all, find, create, update, delete теперь будут наследоваться от BaseRepositoryInterface
    // findBy остается, так как его нет в BaseRepositoryInterface и он специфичен
    public function findBy(string $field, mixed $value, array $columns = ['*']); // Этот метод специфичен и может остаться или быть пересмотрен

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