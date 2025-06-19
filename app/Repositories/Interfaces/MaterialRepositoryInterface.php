<?php

namespace App\Repositories\Interfaces;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use App\Models\Material;
use Illuminate\Support\Collection;

interface MaterialRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Получить материалы для определенной организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsForOrganization(int $organizationId);

    /**
     * Получить материал со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Material|null
     */
    public function findWithRelations(int $id, array $relations = []);

    /**
     * Получить материалы по категории
     *
     * @param int $organizationId
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsByCategory(int $organizationId, string $category);
    
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
    );

    public function findByExternalCode(string $externalCode, int $organizationId): ?Material;

    public function findByNameAndOrganization(string $name, int $organizationId): ?Material;

    public function getMaterialUsageByProjects(int $organizationId, array $projectIds, ?string $dateFrom, ?string $dateTo): Collection;

    public function getMaterialUsageBySuppliers(int $organizationId, array $supplierIds, ?string $dateFrom, ?string $dateTo): Collection;

    public function getMaterialUsageSummary(int $organizationId, ?string $dateFrom, ?string $dateTo): Collection;
    
    public function getActiveMaterials(int $organizationId): Collection;

    public function searchByNameOrCode(int $organizationId, string $searchTerm): Collection;

    public function findByIds(array $ids, int $organizationId): Collection;

    public function getMaterialsWithLowStock(int $organizationId, int $threshold): Collection;

    public function getMostUsedMaterials(int $organizationId, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): Collection;

    public function getMaterialReceiptsHistory(int $materialId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function getMaterialWriteOffsHistory(int $materialId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function updateOrCreateFromAccounting(array $data, int $organizationId): ?Material;

    public function getAllMaterialNames(int $organizationId): Collection;

    public function getMaterialCostHistory(int $organizationId, int $materialId, ?string $dateFrom = null, ?string $dateTo = null): Collection;

    public function getAverageMaterialCost(int $materialId): ?float;

    // Методы для отчетов
    public function getMaterialMovementReport(int $organizationId, array $filters): Collection;
    public function getInventoryReport(int $organizationId, array $filters): Collection;
    public function getMaterialCostDynamicsReport(int $organizationId, array $filters): Collection;
} 