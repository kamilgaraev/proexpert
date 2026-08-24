<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\DTOs;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class FinancialBalance
{
    public function __construct(
        public string $invoicedAmount,
        public string $paidAmount,
        public string $refundedAmount,
        public string $retainedAmount,
        public string $advanceAmount,
        public string $debtAmount,
        public string $overpaymentAmount,
    ) {}

    public static function fromLedger(
        string $invoicedAmount,
        string $paidAmount,
        string $refundedAmount,
        string $retainedAmount = '0',
        string $advanceAmount = '0'
    ): self {
        $invoiced = self::money($invoicedAmount);
        $paid = self::money($paidAmount);
        $refunded = self::money($refundedAmount);
        $retained = self::money($retainedAmount);
        $advance = self::money($advanceAmount);
        $rawDebt = $invoiced->minus($paid)->plus($refunded)->plus($retained);

        return new self(
            (string) $invoiced,
            (string) $paid,
            (string) $refunded,
            (string) $retained,
            (string) $advance,
            (string) ($rawDebt->isPositive() ? $rawDebt : BigDecimal::zero()->toScale(2)),
            (string) ($rawDebt->isNegative() ? $rawDebt->abs() : BigDecimal::zero()->toScale(2)),
        );
    }

    public function toArray(): array
    {
        return [
            'invoiced_amount' => $this->invoicedAmount,
            'paid_amount' => $this->paidAmount,
            'refunded_amount' => $this->refundedAmount,
            'retained_amount' => $this->retainedAmount,
            'advance_amount' => $this->advanceAmount,
            'debt_amount' => $this->debtAmount,
            'overpayment_amount' => $this->overpaymentAmount,
        ];
    }

    private static function money(string $amount): BigDecimal
    {
        return BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
