<?php

namespace App\Repositories;

use App\Models\Supplier;
use App\Repositories\Interfaces\SupplierRepositoryInterface;

class SupplierRepository extends BaseRepository implements SupplierRepositoryInterface
{
    /**
     * Конструктор репозитория поставщиков
     */
    public function __construct()
    {
        parent::__construct(Supplier::class);
    }

    /**
     * Получить активных поставщиков для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveSuppliers(int $organizationId)
    {
        return $this->model
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить поставщиков для организации с фильтрацией и пагинацией.
     */
    public function getSuppliersForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId);

        // Фильтры
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        // Сортировка
        $query->orderBy($sortBy, $sortDirection);

        // Пагинация
        return $query->paginate($perPage);
    }
} 