<?php

namespace App\Repositories;

use App\Models\Material;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
    public function getActiveMaterials(int $organizationId): Collection
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
        $query = $this->model->where('organization_id', $organizationId)
                             ->with('measurementUnit');

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

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Material
    {
        return parent::find($modelId, $columns, $relations, $appends);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }
    // End of RepositoryInterface methods

    // Заглушки для методов интерфейса MaterialRepositoryInterface
    public function findByExternalCode(string $externalCode, int $organizationId): ?Material
    {
        // TODO: Implement findByExternalCode() method.
        return null;
    }

    public function findByNameAndOrganization(string $name, int $organizationId): ?Material
    {
        // TODO: Implement findByNameAndOrganization() method.
        return null;
    }

    public function getMaterialUsageByProjects(int $organizationId, array $projectIds, ?string $dateFrom, ?string $dateTo): Collection
    {
        // TODO: Implement getMaterialUsageByProjects() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialUsageBySuppliers(int $organizationId, array $supplierIds, ?string $dateFrom, ?string $dateTo): Collection
    {
        // TODO: Implement getMaterialUsageBySuppliers() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialUsageSummary(int $organizationId, ?string $dateFrom, ?string $dateTo): Collection
    {
        // TODO: Implement getMaterialUsageSummary() method.
        return new \Illuminate\Support\Collection();
    }

    public function searchByNameOrCode(int $organizationId, string $searchTerm): Collection
    {
        // TODO: Implement searchByNameOrCode() method.
        return new \Illuminate\Support\Collection();
    }

    public function findByIds(array $ids, int $organizationId): Collection
    {
        // TODO: Implement findByIds() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialsWithLowStock(int $organizationId, int $threshold): Collection
    {
        // TODO: Implement getMaterialsWithLowStock() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMostUsedMaterials(int $organizationId, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        // TODO: Implement getMostUsedMaterials() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialReceiptsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        // TODO: Implement getMaterialReceiptsHistory() method.
        // Временная заглушка, реальная пагинация сложнее
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
    }

    public function getMaterialWriteOffsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        // TODO: Implement getMaterialWriteOffsHistory() method.
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
    }

    public function updateOrCreateFromAccounting(array $data, int $organizationId): ?Material
    {
        // TODO: Implement updateOrCreateFromAccounting() method.
        return null;
    }

    public function getAllMaterialNames(int $organizationId): Collection
    {
        // TODO: Implement getAllMaterialNames() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialCostHistory(int $materialId, int $limit = 10): Collection
    {
        // TODO: Implement getMaterialCostHistory() method.
        return new \Illuminate\Support\Collection();
    }

    public function getAverageMaterialCost(int $materialId): ?float
    {
        // TODO: Implement getAverageMaterialCost() method.
        return null;
    }

    public function getMaterialMovementReport(int $organizationId, array $filters): Collection
    {
        // TODO: Implement getMaterialMovementReport() method.
        return new \Illuminate\Support\Collection();
    }

    public function getInventoryReport(int $organizationId, array $filters): Collection
    {
        // TODO: Implement getInventoryReport() method.
        return new \Illuminate\Support\Collection();
    }

    public function getMaterialCostDynamicsReport(int $organizationId, array $filters): Collection
    { 
        // TODO: Implement getMaterialCostDynamicsReport() method.
        return new \Illuminate\Support\Collection();
    }
} 