<?php

namespace App\Repositories;

use App\Models\SupplementaryAgreement;
use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplementaryAgreementRepository extends BaseRepository implements SupplementaryAgreementRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(SupplementaryAgreement::class);
    }

    public function paginateByContract(int $contractId, int $perPage = 15): LengthAwarePaginator
    {
        return SupplementaryAgreement::where('contract_id', $contractId)
            ->orderBy('agreement_date', 'desc')
            ->paginate($perPage);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return SupplementaryAgreement::orderBy('agreement_date', 'desc')->paginate($perPage);
    }

    public function create(array $data): SupplementaryAgreement
    {
        /** @var SupplementaryAgreement $model */
        $model = parent::create($data);
        return $model;
    }
} 