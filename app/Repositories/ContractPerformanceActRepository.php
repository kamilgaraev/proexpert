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

        // Фильтр по проекту (для мультипроектных контрактов)
        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        // Фильтр по статусу утверждения
        if (isset($filters['is_approved'])) {
            $query->where('is_approved', $filters['is_approved']);
        }

        $query->orderBy($sortBy, $sortDirection);
        return $query->get();
    }

    public function getTotalAmountForContract(int $contractId, ?int $projectId = null): float
    {
        $query = $this->model->query()
            ->where('contract_id', $contractId)
            ->where('is_approved', true); // Typically, we sum only approved acts
        
        // Если указан проект, фильтруем по нему
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        
        return (float) $query->sum('amount');
    }
} 