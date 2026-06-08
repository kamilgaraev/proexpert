<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetLimitAmounts
{
    public function __construct(
        public float $approvedBudgetAmount,
        public float $actualPaymentsAmount,
        public float $pendingApprovalAmount,
        public float $reservedAmount,
        public float $carryoverAmount,
        public float $adjustmentAmount,
        public float $exceptionAmount,
        public float $requestedAmount,
    ) {
    }

    public function totalLimitAmount(): float
    {
        return $this->money(
            $this->approvedBudgetAmount
            + $this->carryoverAmount
            + $this->adjustmentAmount
            + $this->exceptionAmount
        );
    }

    public function committedAmount(): float
    {
        return $this->money(
            $this->actualPaymentsAmount
            + $this->pendingApprovalAmount
            + $this->reservedAmount
        );
    }

    public function projectedAmount(): float
    {
        return $this->money($this->committedAmount() + $this->requestedAmount);
    }

    public function availableBeforeRequest(): float
    {
        return $this->money($this->totalLimitAmount() - $this->committedAmount());
    }

    public function availableAfterRequest(): float
    {
        return $this->money($this->totalLimitAmount() - $this->projectedAmount());
    }

    public function excessAmount(): float
    {
        return $this->money(max(0.0, $this->projectedAmount() - $this->totalLimitAmount()));
    }

    public function usageRatio(): float
    {
        $totalLimit = $this->totalLimitAmount();

        if ($totalLimit <= 0.0) {
            return $this->projectedAmount() > 0.0 ? 1.0 : 0.0;
        }

        return round($this->projectedAmount() / $totalLimit, 6);
    }

    public function toArray(): array
    {
        return [
            'approved_budget_amount' => $this->money($this->approvedBudgetAmount),
            'actual_payments_amount' => $this->money($this->actualPaymentsAmount),
            'pending_approval_amount' => $this->money($this->pendingApprovalAmount),
            'reserved_amount' => $this->money($this->reservedAmount),
            'carryover_amount' => $this->money($this->carryoverAmount),
            'adjustment_amount' => $this->money($this->adjustmentAmount),
            'exception_amount' => $this->money($this->exceptionAmount),
            'requested_amount' => $this->money($this->requestedAmount),
            'total_limit_amount' => $this->totalLimitAmount(),
            'committed_amount' => $this->committedAmount(),
            'projected_amount' => $this->projectedAmount(),
            'available_before_request' => $this->availableBeforeRequest(),
            'available_after_request' => $this->availableAfterRequest(),
            'excess_amount' => $this->excessAmount(),
            'usage_ratio' => $this->usageRatio(),
        ];
    }

    public function money(float $amount): float
    {
        return round($amount, 2);
    }
}
