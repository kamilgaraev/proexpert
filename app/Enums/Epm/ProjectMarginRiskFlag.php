<?php

declare(strict_types=1);

namespace App\Enums\Epm;

enum ProjectMarginRiskFlag: string
{
    case CashOnlySource = 'cash_only_source';
    case AccrualWithoutPayment = 'accrual_without_payment';
    case PaymentWithoutAccrual = 'payment_without_accrual';
    case ManualAdjustmentActive = 'manual_adjustment_active';
    case ManualAdjustmentExpiring = 'manual_adjustment_expiring';
    case AutoAllocationWithoutActualBase = 'auto_allocation_without_actual_base';
    case MultiCurrencyWithoutRate = 'multi_currency_without_rate';
    case LateDocumentForClosedPeriod = 'late_document_for_closed_period';
    case IndirectCostPolicySensitive = 'indirect_cost_policy_sensitive';
    case SourceDisputed = 'source_disputed';
    case OneCPeriodDiffers = 'one_c_period_differs';
    case EdoPending = 'edo_pending';
    case BankMatchPending = 'bank_match_pending';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $flag): string => $flag->value,
            self::cases(),
        );
    }
}
