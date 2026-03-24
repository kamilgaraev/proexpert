<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\SupplementaryAgreement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SupplementaryAgreementRepositoryInterface
{
    public function create(array $data): SupplementaryAgreement;

    public function find(int $id, array $columns = ['*'], array $relations = [], array $appends = []): ?SupplementaryAgreement;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function paginateByContract(
        int $contractId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;

    public function paginateByProject(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;

    public function paginate(
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;
}
