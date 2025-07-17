<?php

namespace App\Repositories\RateCoefficient;

use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Models\RateCoefficient;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\RateCoefficientRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class RateCoefficientRepository extends BaseRepository implements RateCoefficientRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(RateCoefficient::class);
    }

    public function getCoefficientsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->query()->where('organization_id', $organizationId);

        // Применение фильтров
        if (!empty($filters)) {
            foreach ($filters as $field => $value) {
                if ($value !== null && $value !== '') {
                    match ($field) {
                        'name', 'code' => $query->where($field, 'like', "%{$value}%"),
                        'type', 'applies_to', 'scope', 'is_active' => $query->where($field, $value),
                        'valid_from_start' => $query->whereDate('valid_from', '>=', $value),
                        'valid_from_end' => $query->whereDate('valid_from', '<=', $value),
                        'valid_to_start' => $query->whereDate('valid_to', '>=', $value),
                        'valid_to_end' => $query->whereDate('valid_to', '<=', $value),
                        default => null,
                    };
                }
            }
        }

        // Сортировка
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    public function findApplicableCoefficients(
        int $organizationId,
        string $appliesTo, // RateCoefficientAppliesToEnum value
        ?string $scope = null,    // RateCoefficientScopeEnum value
        array $contextualIds = [],
        ?string $date = null
    ): Collection
    {
        $query = $this->model->query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('applies_to', $appliesTo);

        $targetDate = $date ? Carbon::parse($date) : Carbon::now();

        $query->where(function (Builder $q) use ($targetDate) {
            $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $targetDate);
        });
        $query->where(function (Builder $q) use ($targetDate) {
            $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $targetDate);
        });

        if ($scope) {
            $query->where('scope', $scope);
        }

        // Дополнительная фильтрация по контекстным идентификаторам
        if (isset($contextualIds['project_id'])) {
            $projectId = $contextualIds['project_id'];
            $query->where(function (Builder $q) use ($projectId) {
                $q->whereJsonContains('conditions->project_ids', $projectId)
                  ->orWhereNull('conditions->project_ids');
            });
        }

        if (isset($contextualIds['material_id'])) {
            $materialId = $contextualIds['material_id'];
            $query->where(function (Builder $q) use ($materialId) {
                $q->whereJsonContains('conditions->material_ids', $materialId)
                  ->orWhereNull('conditions->material_ids');
            });
        }
        
        // TODO: Добавить сортировку по приоритету, если будет такое поле, или по специфичности scope

        return $query->get();
    }
} 