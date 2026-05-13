<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Contractor;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContractorRepository extends BaseRepository implements ContractorRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(Contractor::class);
    }

    public function getContractorsForOrganization(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator {
        $query = $this->model->query()
            ->where('organization_id', $organizationId)
            ->withCount('contracts');

        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['inn'])) {
            $query->where('inn', '=', $filters['inn']);
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }
}
