<?php

namespace App\Repositories;

use App\Models\Material;
use App\Repositories\Interfaces\MaterialRepositoryInterface;

class MaterialRepository extends BaseRepository implements MaterialRepositoryInterface
{
    /**
     * Конструктор репозитория материалов
     */
    public function __construct()
    {
        parent::__construct(Material::class); // Передаем имя класса
    }

    /**
     * Получить материалы для определенной организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsForOrganization(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить материал со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Material|null
     */
    public function findWithRelations(int $id, array $relations = [])
    {
        return $this->model->with($relations)->find($id);
    }

    /**
     * Получить активные материалы организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveMaterials(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }
    
    /**
     * Получить материалы по категории
     *
     * @param int $organizationId
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsByCategory(int $organizationId, string $category)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('category', $category)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить материалы для организации с фильтрацией и пагинацией.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters ['name' => string, 'category' => string, 'is_active' => bool]
     * @param string $sortBy
     * @param string $sortDirection
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getMaterialsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId);

        // Применяем фильтры
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', 'ilike', '%' . $filters['category'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        // Применяем сортировку
        $query->orderBy($sortBy, $sortDirection);

        // Пагинация
        return $query->paginate($perPage);
    }
} 