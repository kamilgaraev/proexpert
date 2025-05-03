<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;

interface WorkTypeRepositoryInterface extends RepositoryInterface
{
    /**
     * Получить активные виды работ для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkTypes(int $organizationId);
} 