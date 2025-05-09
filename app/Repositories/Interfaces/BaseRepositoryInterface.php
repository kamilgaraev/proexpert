<?php

namespace App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface BaseRepositoryInterface
{
    public function getAll(array $columns = ['*'], array $relations = []): Collection;

    public function findById(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Model;

    public function create(array $payload): ?Model;

    public function update(int $modelId, array $payload): bool;

    public function deleteById(int $modelId): bool;
} 