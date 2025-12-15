<?php

namespace App\Repositories\Interfaces;

use App\Models\ContractPerformanceAct;
use Illuminate\Support\Collection;

interface ContractPerformanceActRepositoryInterface extends BaseRepositoryInterface
{
    public function getActsForContract(int $contractId, array $filters = [], string $sortBy = 'act_date', string $sortDirection = 'desc'): Collection;
    public function getTotalAmountForContract(int $contractId, ?int $projectId = null): float;
} 