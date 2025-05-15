<?php

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

    public function getContractorsForOrganization(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'name', string $sortDirection = 'asc'): LengthAwarePaginator
    {
        $query = $this->model->query()->where('organization_id', $organizationId);

        // Пример обработки дополнительных фильтров, переданных из сервиса
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['inn'])) {
            $query->where('inn', '=', $filters['inn']);
        }
        // ... другие фильтры ...

        // Добавляем подсчет связанных контрактов
        $query->withCount('contracts');

        $query->orderBy($sortBy, $sortDirection);
        
        return $query->paginate($perPage);
    }

    // Implementations for ContractorRepositoryInterface methods can be added here later
    // e.g. findByInn, getContractorsForOrganization
} 