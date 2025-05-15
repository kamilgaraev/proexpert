<?php

namespace App\Repositories\Eloquent;

use App\Models\MeasurementUnit;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentMeasurementUnitRepository extends BaseRepository implements MeasurementUnitRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(MeasurementUnit::class);
    }

    public function findById(int $id, int $organizationId, array $columns = ['*'], array $relations = [], array $appends = []): ?MeasurementUnit
    {
        return $this->model->select($columns)
            ->with($relations)
            ->where('organization_id', $organizationId)
            ->find($id)
            ?->append($appends);
    }

    public function getByOrganization(int $organizationId, array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model->select($columns)
                            ->with($relations)
                            ->where('organization_id', $organizationId)
                            ->orWhere('is_system', true)
                            ->orderBy('name')
                            ->get();
    }

    public function resetDefaultFlag(int $organizationId, string $type, ?int $excludeId = null): bool
    {
        $query = $this->model->where('organization_id', $organizationId)
                             ->where('type', $type)
                             ->where('is_default', true);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->update(['is_default' => false]);
    }

    public function getUnitsByType(int $organizationId, string $type, array $columns = ['*']): Collection
    {
        return $this->model->select($columns)
                            ->where('organization_id', $organizationId)
                            ->where('type', $type)
                            ->orWhere(function ($query) use ($type) {
                                $query->where('is_system', true)
                                      ->where('type', $type);
                            })
                            ->orderBy('name')
                            ->get();
    }
} 