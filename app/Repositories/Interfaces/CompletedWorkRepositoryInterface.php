<?php

namespace App\Repositories\Interfaces;

use App\Models\CompletedWork;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompletedWorkRepositoryInterface extends BaseRepositoryInterface
{
    public function getAllPaginated(
        array $filters = [], 
        int $perPage = 15, 
        string $sortBy = 'id', 
        string $sortDirection = 'asc', 
        array $relations = []
    ): LengthAwarePaginator;

    public function findById(int $id, int $organizationId): ?CompletedWork;

    // Можно добавить специфичные для CompletedWork методы, если понадобятся
} 