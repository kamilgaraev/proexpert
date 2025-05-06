<?php

namespace App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Collection;
use App\Models\MeasurementUnit; // Предполагаем, что модель существует

interface MeasurementUnitRepositoryInterface
{
    /**
     * Получить все единицы измерения.
     *
     * @return Collection<int, MeasurementUnit>
     */
    public function all(): Collection;

    /**
     * Найти единицу измерения по ID.
     *
     * @param int $id
     * @return MeasurementUnit|null
     */
    public function find(int $id): ?MeasurementUnit;
    
    // Можно добавить другие методы, если они понадобятся, например:
    // public function getActiveUnitsForOrganization(int $organizationId): Collection;
    // public function create(array $data): MeasurementUnit;
    // public function update(int $id, array $data): bool;
    // public function delete(int $id): bool;
} 