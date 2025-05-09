<?php

namespace App\Repositories;

use App\Models\WorkType;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class WorkTypeRepository extends BaseRepository implements WorkTypeRepositoryInterface
{
    /**
     * Конструктор репозитория видов работ
     */
    public function __construct()
    {
        parent::__construct(WorkType::class); // Передаем имя класса
    }

    /**
     * Получить активные виды работ для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkTypes(int $organizationId)
    {
        return $this->model
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit') // Добавим ед. изм.
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить виды работ для организации с фильтрацией и пагинацией.
     */
    public function getWorkTypesForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId)
                              ->with('measurementUnit');

        // Фильтры
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', 'ilike', '%' . $filters['category'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        // Сортировка
        $query->orderBy($sortBy, $sortDirection);

        // Пагинация
        return $query->paginate($perPage);
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $id, array $columns = ['*']): ?WorkType
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