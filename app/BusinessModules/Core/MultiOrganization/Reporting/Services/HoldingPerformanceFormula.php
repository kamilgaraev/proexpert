<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingPerformanceMetricRow;
use App\Enums\CurrencyCode;

final readonly class HoldingPerformanceFormula
{
    public function row(HoldingAllocationFact $fact, ?string $periodStart = null): HoldingPerformanceMetricRow
    {
        $currency = $fact->currency === null
            ? null
            : CurrencyCode::tryFrom(mb_strtoupper($fact->currency))?->value;

        return new HoldingPerformanceMetricRow(
            organizationId: $fact->organizationId,
            holdingId: $fact->holdingId,
            contributorOrganizationId: $fact->contributorOrganizationId,
            projectId: $fact->projectId,
            currency: $currency,
            periodStart: $periodStart ?? substr($fact->recognizedOn, 0, 7).'-01',
            monetaryBasis: $fact->monetaryBasis,
            contractedMinor: $fact->monetaryBasis === 'contracted' ? $fact->amountMinor : 0,
            acceptedAccrualMinor: $fact->monetaryBasis === 'accepted_accrual' ? $fact->amountMinor : 0,
            cashMinor: $fact->monetaryBasis === 'cash' ? $fact->amountMinor : 0,
            rowKey: hash('sha256', $fact->sourceKey()),
            sourceRefs: $fact->sourceRefs,
        );
    }

    public function totals(iterable $rows): array
    {
        $currencies = [];
        $unknownCurrencyCount = 0;
        $eligibleCount = 0;

        foreach ($rows as $row) {
            if (! $row instanceof HoldingPerformanceMetricRow) {
                continue;
            }
            $eligibleCount++;

            if ($row->currency === null) {
                $unknownCurrencyCount++;
                continue;
            }

            $currencies[$row->currency] ??= [
                'contracted_minor' => 0,
                'accepted_accrual_minor' => 0,
                'cash_minor' => 0,
            ];
            $currencies[$row->currency]['contracted_minor'] += $row->contractedMinor;
            $currencies[$row->currency]['accepted_accrual_minor'] += $row->acceptedAccrualMinor;
            $currencies[$row->currency]['cash_minor'] += $row->cashMinor;
        }

        ksort($currencies, SORT_STRING);

        return [
            'currencies' => $currencies,
            'quality' => [
                'eligible_count' => $eligibleCount,
                'unknown_currency_count' => $unknownCurrencyCount,
                'excluded_amount_minor' => null,
            ],
        ];
    }
}
