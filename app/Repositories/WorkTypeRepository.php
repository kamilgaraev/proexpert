<?php

namespace App\Repositories;

use App\Models\WorkType;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WorkTypeRepository extends BaseRepository implements WorkTypeRepositoryInterface
{
    /**
     * Конструктор репозитория видов работ
     */
    public function __construct()
    {
        parent::__construct(WorkType::class); // Передаем имя класса
    }

    /**
     * Получить активные виды работ для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkTypes(int $organizationId)
    {
        return $this->model
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit') // Добавим ед. изм.
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить виды работ для организации с фильтрацией и пагинацией.
     */
    public function getWorkTypesForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator
    {
        return $this->filteredWorkTypes($organizationId, $filters)
            ->with('measurementUnit')
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function getWorkTypeSummaryForOrganization(int $organizationId, array $filters = []): array
    {
        $summary = $this->filteredWorkTypes($organizationId, $filters)->toBase()
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active, COUNT(DISTINCT category) FILTER (WHERE category <> '') AS categories")
            ->first();

        return [
            'total' => (int) ($summary->total ?? 0),
            'active' => (int) ($summary->active ?? 0),
            'categories' => (int) ($summary->categories ?? 0),
        ];
    }

    private function filteredWorkTypes(int $organizationId, array $filters): Builder
    {
        $query = $this->model->where('organization_id', $organizationId);

        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', 'ilike', '%' . $filters['category'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        return $query;
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?WorkType
    {
        return parent::find($modelId, $columns, $relations, $appends);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }
    // End of RepositoryInterface methods
}
