<?php

namespace App\Repositories;

use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ContractRepository extends BaseRepository implements ContractRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(Contract::class);
    }

    public function getContractsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->query()->where('organization_id', $organizationId);

        // Apply filters (example)
        if (!empty($filters['contractor_id'])) {
            $query->where('contractor_id', $filters['contractor_id']);
        }
        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['number'])) {
            $query->where('number', 'ilike', '%' . $filters['number'] . '%');
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        // Eager load relationships as needed, for example:
        $query->with(['contractor:id,name', 'project:id,name']);

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }
} 