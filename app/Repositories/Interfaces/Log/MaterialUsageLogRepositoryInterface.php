<?php

namespace App\Repositories\Interfaces\Log;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface MaterialUsageLogRepositoryInterface extends BaseRepositoryInterface
{
    public function getAggregatedUsage(int $organizationId, array $filters = []): Collection;

    public function getPaginatedLogs(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'usage_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;
}
