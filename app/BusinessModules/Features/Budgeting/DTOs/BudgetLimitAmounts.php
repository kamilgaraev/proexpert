<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class BudgetLimitAmounts
{
    public string $approvedBudgetAmount;

    public string $actualPaymentsAmount;

    public string $pendingApprovalAmount;

    public string $reservedAmount;

    public string $carryoverAmount;

    public string $adjustmentAmount;

    public string $exceptionAmount;

    public string $requestedAmount;

    public function __construct(
        string|int|float $approvedBudgetAmount,
        string|int|float $actualPaymentsAmount,
        string|int|float $pendingApprovalAmount,
        string|int|float $reservedAmount,
        string|int|float $carryoverAmount,
        string|int|float $adjustmentAmount,
        string|int|float $exceptionAmount,
        string|int|float $requestedAmount,
    ) {
        $this->approvedBudgetAmount = $this->money($approvedBudgetAmount);
        $this->actualPaymentsAmount = $this->money($actualPaymentsAmount);
        $this->pendingApprovalAmount = $this->money($pendingApprovalAmount);
        $this->reservedAmount = $this->money($reservedAmount);
        $this->carryoverAmount = $this->money($carryoverAmount);
        $this->adjustmentAmount = $this->money($adjustmentAmount);
        $this->exceptionAmount = $this->money($exceptionAmount);
        $this->requestedAmount = $this->money($requestedAmount);
    }

    public function totalLimitAmount(): string
    {
        return $this->sum([
            $this->approvedBudgetAmount,
            $this->carryoverAmount,
            $this->adjustmentAmount,
            $this->exceptionAmount,
        ]);
    }

    public function committedAmount(): string
    {
        return $this->sum([
            $this->actualPaymentsAmount,
            $this->pendingApprovalAmount,
            $this->reservedAmount,
        ]);
    }

    public function projectedAmount(): string
    {
        return $this->sum([$this->committedAmount(), $this->requestedAmount]);
    }

    public function availableBeforeRequest(): string
    {
        return $this->subtract($this->totalLimitAmount(), $this->committedAmount());
    }

    public function availableAfterRequest(): string
    {
        return $this->subtract($this->totalLimitAmount(), $this->projectedAmount());
    }

    public function excessAmount(): string
    {
        $excess = BigDecimal::of($this->projectedAmount())->minus(BigDecimal::of($this->totalLimitAmount()));

        return $this->money($excess->isPositive() ? (string) $excess : '0');
    }

    public function hasRequestedAmount(): bool
    {
        return BigDecimal::of($this->requestedAmount)->isPositive();
    }

    public function hasExcess(): bool
    {
        return BigDecimal::of($this->excessAmount())->isPositive();
    }

    public function usageRatio(): float
    {
        $totalLimit = BigDecimal::of($this->totalLimitAmount());
        if (! $totalLimit->isPositive()) {
            return BigDecimal::of($this->projectedAmount())->isPositive() ? 1.0 : 0.0;
        }

        return (float) (string) BigDecimal::of($this->projectedAmount())
            ->dividedBy($totalLimit, 6, RoundingMode::HalfUp);
    }

    public function toArray(): array
    {
        return [
            'approved_budget_amount' => $this->approvedBudgetAmount,
            'actual_payments_amount' => $this->actualPaymentsAmount,
            'pending_approval_amount' => $this->pendingApprovalAmount,
            'reserved_amount' => $this->reservedAmount,
            'carryover_amount' => $this->carryoverAmount,
            'adjustment_amount' => $this->adjustmentAmount,
            'exception_amount' => $this->exceptionAmount,
            'requested_amount' => $this->requestedAmount,
            'total_limit_amount' => $this->totalLimitAmount(),
            'committed_amount' => $this->committedAmount(),
            'projected_amount' => $this->projectedAmount(),
            'available_before_request' => $this->availableBeforeRequest(),
            'available_after_request' => $this->availableAfterRequest(),
            'excess_amount' => $this->excessAmount(),
            'usage_ratio' => $this->usageRatio(),
        ];
    }

    public function money(string|int|float $amount): string
    {
        return (string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
    }

    private function sum(array $amounts): string
    {
        $total = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus(BigDecimal::of((string) $amount));
        }

        return $this->money((string) $total);
    }

    private function subtract(string $left, string $right): string
    {
        return $this->money((string) BigDecimal::of($left)->minus(BigDecimal::of($right)));
    }
}
