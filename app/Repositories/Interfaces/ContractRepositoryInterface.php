<?php

namespace App\Repositories\Interfaces;

use App\Models\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractRepositoryInterface extends BaseRepositoryInterface
{
    public function getContractsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;

    /**
     * Получить статистику по выполненным работам контракта
     */
    public function getContractWorksStatistics(int $contractId): array;

    /**
     * Получить последние выполненные работы по контракту
     */
    public function getRecentCompletedWorks(int $contractId, int $limit = 10): \Illuminate\Database\Eloquent\Collection;

    // public function getSumOfActiveContracts(int $organizationId): float;
    // public function getOverdueContracts(int $organizationId): Collection;
} 