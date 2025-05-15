<?php

namespace App\Repositories\Interfaces;

use App\Models\Contractor;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractorRepositoryInterface extends BaseRepositoryInterface // Предполагаем, что BaseRepositoryInterface существует
{
    public function getContractorsForOrganization(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'name', string $sortDirection = 'asc'): LengthAwarePaginator;
    // public function findByInn(string $inn, int $organizationId): ?Contractor;
} 