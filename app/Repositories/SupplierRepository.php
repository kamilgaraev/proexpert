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
} 