<?php

namespace App\Repositories;

use App\Models\ContractPerformanceAct;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class ContractPerformanceActRepository extends BaseRepository implements ContractPerformanceActRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(ContractPerformanceAct::class);
    }

    public function getActsForContract(int $contractId, array $filters = [], string $sortBy = 'act_date', string $sortDirection = 'desc'): Collection
    {
        $query = $this->model->query()->where('contract_id', $contractId);

        // Example filter
        if (isset($filters['is_approved'])) {
            $query->where('is_approved', $filters['is_approved']);
        }

        $query->orderBy($sortBy, $sortDirection);
        return $query->get();
    }

    public function getTotalAmountForContract(int $contractId): float
    {
        return (float) $this->model->query()
            ->where('contract_id', $contractId)
            ->where('is_approved', true) // Typically, we sum only approved acts
            ->sum('amount');
    }
} 