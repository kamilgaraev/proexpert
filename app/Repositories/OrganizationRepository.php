<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use Illuminate\Support\Collection;

class OrganizationRepository extends BaseRepository implements OrganizationRepositoryInterface
{
    /**
     * OrganizationRepository constructor.
     */
    public function __construct()
    {
        parent::__construct(Organization::class); // Передаем имя класса
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $id, array $columns = ['*']): ?Organization
    {
        return parent::findById($id, $columns);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    // create(array $data) - предполагаем, что не входит в "4 abstract methods" из-за совпадения имени с parent::create
    // update(int $id, array $data) - предполагаем, что не входит в "4 abstract methods"

    public function delete(int $id): bool
    {
        return parent::deleteById($id);
    }
    // End of RepositoryInterface methods

    public function findWithUsers(int $id): ?Organization
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