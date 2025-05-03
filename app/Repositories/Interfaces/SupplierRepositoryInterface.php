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
} 