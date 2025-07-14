<?php

namespace App\Repositories\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\SupplementaryAgreement;

interface SupplementaryAgreementRepositoryInterface
{
    public function create(array $data): SupplementaryAgreement;
    public function find(int $id): ?SupplementaryAgreement;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginateByContract(int $contractId, int $perPage = 15): LengthAwarePaginator;
} 