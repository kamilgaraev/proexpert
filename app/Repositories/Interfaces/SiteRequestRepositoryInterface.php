<?php

namespace App\Repositories\Interfaces;

use App\Models\SiteRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SiteRequestRepositoryInterface extends BaseRepositoryInterface
{
    public function getAllPaginated(
        array $filters = [], 
        int $perPage = 15, 
        string $sortBy = 'id', 
        string $sortDirection = 'asc', 
        array $relations = []
    ): LengthAwarePaginator;

    public function findById(int $id, int $organizationId, array $relations = []): ?SiteRequest;
} 