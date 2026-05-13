<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractorRepositoryInterface extends BaseRepositoryInterface
{
    public function getContractorsForOrganization(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator;
}
