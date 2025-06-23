<?php

namespace App\Repositories;

use App\Models\ContractPayment;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class ContractPaymentRepository extends BaseRepository implements ContractPaymentRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(ContractPayment::class);
    }

    public function getPaymentsForContract(int $contractId, array $filters = [], string $sortBy = 'payment_date', string $sortDirection = 'desc'): Collection
    {
        $query = $this->model->query()->where('contract_id', $contractId);

        // Example filter
        if (!empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        $query->orderBy($sortBy, $sortDirection);
        return $query->get();
    }

    public function getTotalPaidAmountForContract(int $contractId, ?string $paymentType = null): float
    {
        $query = $this->model->query()->where('contract_id', $contractId);

        if ($paymentType) {
            $query->where('payment_type', $paymentType);
        }

        return (float) $query->sum('amount');
    }

    public function getAdvancePaymentsSum(int $contractId): float
    {
        return (float) $this->model->query()
            ->where('contract_id', $contractId)
            ->where('payment_type', ContractPaymentTypeEnum::ADVANCE)
            ->sum('amount');
    }
} 