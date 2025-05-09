<?php

namespace App\Repositories\Eloquent;

use App\Models\MeasurementUnit;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentMeasurementUnitRepository implements MeasurementUnitRepositoryInterface
{
    public function all(): Collection
    {
        return MeasurementUnit::orderBy('name')->get();
    }

    public function find(int $id): ?MeasurementUnit
    {
        return MeasurementUnit::find($id);
    }

    public function getByOrganization(int $organizationId): Collection
    {
        // Возвращаем единицы измерения, принадлежащие указанной организации,
        // ИЛИ системные/общие единицы измерения (если у вас такие есть и они помечены, например, organization_id = null)
        // В данном случае, основываясь на сидере, мы ищем строго по organization_id
        return MeasurementUnit::where('organization_id', $organizationId)
                            ->orderBy('name')
                            ->get();
    }

    // При необходимости можно добавить реализацию методов create, update, delete
    // public function create(array $data): MeasurementUnit
    // {
    //     return MeasurementUnit::create($data);
    // }

    // public function update(int $id, array $data): bool
    // {
    //     $unit = $this->find($id);
    //     if ($unit) {
    //         return $unit->update($data);
    //     }
    //     return false;
    // }

    // public function delete(int $id): bool
    // {
    //     $unit = $this->find($id);
    //     if ($unit) {
    //         return $unit->delete();
    //     }
    //     return false;
    // }
} 