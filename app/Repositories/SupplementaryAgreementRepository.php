<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\SupplementaryAgreement;
use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SupplementaryAgreementRepository extends BaseRepository implements SupplementaryAgreementRepositoryInterface
{
    private const ALLOWED_SORT_FIELDS = [
        'agreement_date',
        'number',
        'change_amount',
        'created_at',
        'updated_at',
    ];

    public function __construct()
    {
        parent::__construct(SupplementaryAgreement::class);
    }

    public function paginateByContract(
        int $contractId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $query = SupplementaryAgreement::query()->where('contract_id', $contractId);

        return $this->applyFiltersAndPaginate($query, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function paginateByProject(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $query = SupplementaryAgreement::query()
            ->whereHas('contract', function (Builder $contractQuery) use ($projectId, $organizationId): void {
                $contractQuery
                    ->where(function (Builder $accessQuery) use ($organizationId): void {
                        $accessQuery
                            ->where('contracts.organization_id', $organizationId)
                            ->orWhere(function (Builder $contractorQuery) use ($organizationId): void {
                                $contractorQuery
                                    ->where(function (Builder $selfExecutionQuery): void {
                                        $selfExecutionQuery
                                            ->where('contracts.is_self_execution', false)
                                            ->orWhereNull('contracts.is_self_execution');
                                    })
                                    ->whereHas('contractor', function (Builder $contractorRelation) use ($organizationId): void {
                                        $contractorRelation->where('source_organization_id', $organizationId);
                                    });
                            });
                    })
                    ->where(function (Builder $projectQuery) use ($projectId): void {
                        $projectQuery
                            ->where(function (Builder $singleProjectQuery) use ($projectId): void {
                                $singleProjectQuery
                                    ->where('contracts.is_multi_project', false)
                                    ->where('contracts.project_id', $projectId);
                            })
                            ->orWhere(function (Builder $multiProjectQuery) use ($projectId): void {
                                $multiProjectQuery
                                    ->where('contracts.is_multi_project', true)
                                    ->whereHas('projects', function (Builder $projectsQuery) use ($projectId): void {
                                        $projectsQuery->where('projects.id', $projectId);
                                    });
                            });
                    });
            });

        return $this->applyFiltersAndPaginate($query, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function paginate(
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $query = SupplementaryAgreement::query();

        return $this->applyFiltersAndPaginate($query, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function create(array $data): SupplementaryAgreement
    {
        /** @var SupplementaryAgreement $model */
        $model = parent::create($data);

        return $model;
    }

    public function find(int $id, array $columns = ['*'], array $relations = [], array $appends = []): ?SupplementaryAgreement
    {
        /** @var SupplementaryAgreement|null $model */
        $model = SupplementaryAgreement::with($relations)->find($id, $columns);
        if ($model && $appends) {
            $model->append($appends);
        }

        return $model;
    }

    private function applyFiltersAndPaginate(
        Builder $query,
        int $perPage,
        array $filters,
        string $sortBy,
        string $sortDirection
    ): LengthAwarePaginator {
        if (!empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }

        if (!empty($filters['number'])) {
            $query->where('number', 'ilike', '%' . $filters['number'] . '%');
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('agreement_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('agreement_date', '<=', $filters['date_to']);
        }

        $normalizedSortBy = in_array($sortBy, self::ALLOWED_SORT_FIELDS, true) ? $sortBy : 'agreement_date';
        $normalizedSortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($normalizedSortBy, $normalizedSortDirection)
            ->paginate($perPage);
    }
}
