<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;

interface SupplierRepositoryInterface extends RepositoryInterface
{
    /**
     * Получить активных поставщиков для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveSuppliers(int $organizationId);
    
    /**
     * Получить поставщиков для организации с фильтрацией и пагинацией.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters ['name' => string, 'is_active' => bool]
     * @param string $sortBy
     * @param string $sortDirection
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getSuppliersForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    );
} 