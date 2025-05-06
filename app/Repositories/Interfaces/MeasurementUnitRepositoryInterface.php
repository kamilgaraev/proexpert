<?php

namespace App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Collection;
use App\Models\MeasurementUnit; // Предполагаем, что модель существует

interface MeasurementUnitRepositoryInterface
{
    /**
     * Получить все единицы измерения (может быть использовано для суперадмина или системных нужд).
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

    /**
     * Получить все единицы измерения для указанной организации.
     *
     * @param int $organizationId
     * @return Collection<int, MeasurementUnit>
     */
    public function getByOrganization(int $organizationId): Collection;
    
    // Можно добавить другие методы, если они понадобятся, например:
    // public function create(array $data): MeasurementUnit;
    // public function update(int $id, array $data): bool;
    // public function delete(int $id): bool;
} 