<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

use function trans_message;

final class FixedContractActAmountGuard
{
    /**
     * @return array{is_fixed: bool, contract_amount: ?string, approved_amount: string, remaining_amount: ?string, currency: string}
     */
    public function summary(Contract $contract, ?int $excludedActId = null): array
    {
        $currency = strtoupper(trim((string) ($contract->currency ?: 'RUB')));
        $approvedAmount = $this->approvedAmount($contract, $excludedActId);

        if (! $contract->is_fixed_amount) {
            return [
                'is_fixed' => false,
                'contract_amount' => null,
                'approved_amount' => (string) $approvedAmount,
                'remaining_amount' => null,
                'currency' => $currency,
            ];
        }

        $contractAmount = BigDecimal::of((string) ($contract->total_amount_with_gp ?? 0))
            ->toScale(2, RoundingMode::HalfUp);
        $remainingAmount = $contractAmount->minus($approvedAmount);
        if ($remainingAmount->isNegative()) {
            $remainingAmount = BigDecimal::zero()->toScale(2);
        }

        return [
            'is_fixed' => true,
            'contract_amount' => (string) $contractAmount,
            'approved_amount' => (string) $approvedAmount,
            'remaining_amount' => (string) $remainingAmount,
            'currency' => $currency,
        ];
    }

    public function assertActFits(Contract $contract, string|int|float $actAmount, ?int $excludedActId = null): void
    {
        if (! $contract->is_fixed_amount) {
            return;
        }

        $summary = $this->summary($contract, $excludedActId);
        $amount = BigDecimal::of((string) $actAmount)->toScale(2, RoundingMode::HalfUp);
        $remainingAmount = BigDecimal::of((string) $summary['remaining_amount']);

        if ($amount->compareTo($remainingAmount) <= 0) {
            return;
        }

        throw new BusinessLogicException(
            trans_message('act_reports.fixed_contract_amount_exceeded', [
                'act_amount' => $this->formatMoney($amount, $summary['currency']),
                'contract_amount' => $this->formatMoney(
                    BigDecimal::of((string) $summary['contract_amount']),
                    $summary['currency'],
                ),
                'approved_amount' => $this->formatMoney(
                    BigDecimal::of($summary['approved_amount']),
                    $summary['currency'],
                ),
                'remaining_amount' => $this->formatMoney($remainingAmount, $summary['currency']),
            ]),
            422,
        );
    }

    private function approvedAmount(Contract $contract, ?int $excludedActId): BigDecimal
    {
        $query = $contract->performanceActs()
            ->whereIn('status', [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ]);

        if ($excludedActId !== null) {
            $query->whereKeyNot($excludedActId);
        }

        return BigDecimal::of((string) $query->sum('amount'))->toScale(2, RoundingMode::HalfUp);
    }

    private function formatMoney(BigDecimal $amount, string $currency): string
    {
        $formattedAmount = number_format((float) (string) $amount, 2, ',', ' ');
        $currencyLabel = match ($currency) {
            'RUB' => '₽',
            default => $currency,
        };

        return $formattedAmount.' '.$currencyLabel;
    }
}
