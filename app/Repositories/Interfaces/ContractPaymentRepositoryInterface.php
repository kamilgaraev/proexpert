<?php

namespace App\Repositories\Interfaces;

use App\Models\ContractPayment;
use Illuminate\Support\Collection;

interface ContractPaymentRepositoryInterface extends BaseRepositoryInterface
{
    public function getPaymentsForContract(int $contractId, array $filters = [], string $sortBy = 'payment_date', string $sortDirection = 'desc'): Collection;
    public function getTotalPaidAmountForContract(int $contractId, ?string $paymentType = null): float;
} 