<?php

namespace App\Repositories\Interfaces;

use App\Repositories\Interfaces\BaseRepositoryInterface;

interface WorkTypeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Получить активные виды работ для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkTypes(int $organizationId);
    
    /**
     * Получить виды работ для организации с фильтрацией и пагинацией.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters ['name' => string, 'category' => string, 'is_active' => bool]
     * @param string $sortBy
     * @param string $sortDirection
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getWorkTypesForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    );
} 