<?php

namespace App\Repositories\RateCoefficient;

use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Models\RateCoefficient;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\RateCoefficientRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

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

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    public function findApplicableCoefficients(
        int $organizationId,
        string $appliesTo,
        ?string $scope = null,
        array $contextualIds = [],
        ?string $date = null
    ): Collection
    {
        $query = $this->model->query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('applies_to', $appliesTo);

        $targetDate = $date ? Carbon::parse($date) : Carbon::now();

        $query->where(function (Builder $q) use ($targetDate): void {
            $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $targetDate);
        });

        $query->where(function (Builder $q) use ($targetDate): void {
            $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $targetDate);
        });

        if ($scope) {
            $query->where('scope', $scope);
        }

        if (isset($contextualIds['project_id'])) {
            $projectId = $contextualIds['project_id'];
            $query->where(function (Builder $q) use ($projectId): void {
                $q->whereJsonContains('conditions->project_ids', $projectId)
                    ->orWhereNull('conditions->project_ids');
            });
        }

        if (isset($contextualIds['material_id'])) {
            $materialId = $contextualIds['material_id'];
            $query->where(function (Builder $q) use ($materialId): void {
                $q->whereJsonContains('conditions->material_ids', $materialId)
                    ->orWhereNull('conditions->material_ids');
            });
        }

        $query->orderByRaw(
            'CASE scope ' .
            'WHEN ? THEN 1 ' .
            'WHEN ? THEN 2 ' .
            'WHEN ? THEN 3 ' .
            'WHEN ? THEN 4 ' .
            'WHEN ? THEN 5 ' .
            'WHEN ? THEN 6 ' .
            'ELSE 7 END',
            [
                RateCoefficientScopeEnum::MATERIAL->value,
                RateCoefficientScopeEnum::WORK_TYPE->value,
                RateCoefficientScopeEnum::MATERIAL_CATEGORY->value,
                RateCoefficientScopeEnum::WORK_TYPE_CATEGORY->value,
                RateCoefficientScopeEnum::PROJECT->value,
                RateCoefficientScopeEnum::GLOBAL_ORG->value,
            ]
        );

        return $query->get();
    }
}
