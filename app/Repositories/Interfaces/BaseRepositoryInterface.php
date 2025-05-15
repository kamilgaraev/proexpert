<?php

namespace App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BaseRepositoryInterface
{
    public function getAll(array $columns = ['*'], array $relations = []): Collection;

    public function getAllPaginated(array $filters = [], int $perPage = 15, string $sortBy = 'id', string $sortDirection = 'asc', array $relations = []): LengthAwarePaginator;

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Model;

    public function firstByFilters(array $filters, array $columns = ['*'], array $relations = [], array $appends = []): ?Model;

    public function create(array $payload): ?Model;

    public function update(int $modelId, array $payload): bool;

    public function delete(int $modelId): bool;
} 