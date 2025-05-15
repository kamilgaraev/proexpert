<?php

namespace App\Repositories\Interfaces;

use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection; // Основной тип коллекции
use Illuminate\Pagination\LengthAwarePaginator;

interface MeasurementUnitRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Найти единицу измерения по ID для конкретной организации.
     *
     * @param int $id
     * @param int $organizationId
     * @param array $columns
     * @param array $relations
     * @param array $appends
     * @return MeasurementUnit|null
     */
    public function findById(int $id, int $organizationId, array $columns = ['*'], array $relations = [], array $appends = []): ?MeasurementUnit;

    /**
     * Получить все единицы измерения для указанной организации (непагинированный список).
     *
     * @param int $organizationId
     * @param array $columns
     * @param array $relations
     * @return Collection<int, MeasurementUnit>
     */
    public function getByOrganization(int $organizationId, array $columns = ['*'], array $relations = []): Collection;

    /**
     * Сбросить флаг is_default для всех единиц измерения данного типа в организации.
     *
     * @param int $organizationId
     * @param string $type
     * @param int|null $excludeId
     * @return bool
     */
    public function resetDefaultFlag(int $organizationId, string $type, ?int $excludeId = null): bool;

    /**
     * Получить единицы измерения по типу для организации.
     *
     * @param int $organizationId
     * @param string $type
     * @param array $columns
     * @return Collection<int, MeasurementUnit>
     */
    public function getUnitsByType(int $organizationId, string $type, array $columns = ['*']): Collection;
} 