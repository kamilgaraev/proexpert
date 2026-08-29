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
    public function paginateForOrganization(int $organizationId, int $perPage = 15): LengthAwarePaginator;
    public function findForOrganization(int $id, int $organizationId): ?Specification;
    public function updateForOrganization(int $id, int $organizationId, array $data): ?Specification;
    public function deleteForOrganization(int $id, int $organizationId): bool;
    public function paginateByProject(int $projectId, int $perPage = 15): LengthAwarePaginator;
    public function paginateByProjectForOrganization(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
    ): LengthAwarePaginator;
}
