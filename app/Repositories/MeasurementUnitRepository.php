<?php

namespace App\Repositories;

use App\Models\MeasurementUnit; // Предполагаем, что модель существует
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class MeasurementUnitRepository implements MeasurementUnitRepositoryInterface
{
    /**
     * Получить все единицы измерения.
     *
     * @return Collection<int, MeasurementUnit>
     */
    public function all(): Collection
    {
        try {
            return MeasurementUnit::all();
        } catch (\Throwable $e) {
            Log::error('Error in MeasurementUnitRepository@all: ' . $e->getMessage());
            return new Collection(); // Возвращаем пустую коллекцию в случае ошибки
        }
    }

    /**
     * Найти единицу измерения по ID.
     *
     * @param int $id
     * @return MeasurementUnit|null
     */
    public function find(int $id): ?MeasurementUnit
    {
        try {
            return MeasurementUnit::find($id);
        } catch (\Throwable $e) {
            Log::error("Error in MeasurementUnitRepository@find for ID {$id}: " . $e->getMessage());
            return null; // Возвращаем null в случае ошибки
        }
    }

    /**
     * Получить все единицы измерения для указанной организации.
     *
     * @param int $organizationId
     * @return Collection<int, MeasurementUnit>
     */
    public function getByOrganization(int $organizationId): Collection
    {
        try {
            return MeasurementUnit::where('organization_id', $organizationId)->get();
        } catch (\Throwable $e) {
            Log::error("Error in MeasurementUnitRepository@getByOrganization for Organization ID {$organizationId}: " . $e->getMessage());
            return new Collection(); // Возвращаем пустую коллекцию в случае ошибки
        }
    }
    
    // Реализации других методов интерфейса, если они будут добавлены
} 