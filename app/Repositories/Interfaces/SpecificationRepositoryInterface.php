<?php

namespace App\Repositories\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\Specification;

interface SpecificationRepositoryInterface
{
    public function create(array $data): Specification;
    public function find(int $id, array $columns = ['*'], array $relations = [], array $appends = []): ?Specification;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
} 