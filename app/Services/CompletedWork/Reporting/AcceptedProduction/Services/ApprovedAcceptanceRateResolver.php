<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Enums\CurrencyCode;
use App\Models\PerformanceActCompletedWork;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ApprovedAcceptanceRate;
use InvalidArgumentException;

final readonly class ApprovedAcceptanceRateResolver
{
    public function fromLine(PerformanceActLine $line, ?string $actCurrency = null): ApprovedAcceptanceRate
    {
        [$currency, $currencySource] = $this->currencyWithSource(
            $line->getAttribute('currency'),
            $actCurrency,
            'performance_act_line.currency',
        );

        return new ApprovedAcceptanceRate(
            $this->moneyMinor($line->unit_price),
            $currency,
            'performance_act_line.unit_price@'.$currencySource,
        );
    }

    public function fromPivot(PerformanceActCompletedWork $pivot, ?string $actCurrency = null): ApprovedAcceptanceRate
    {
        $quantityScaled = AcceptedProductionQuantity::scaled(
            (string) $pivot->included_quantity,
            'approved_acceptance_quantity_invalid',
        );
        $amountMinor = $this->moneyMinor($pivot->included_amount);
        if ($quantityScaled < 1) {
            throw new InvalidArgumentException('approved_acceptance_rate_not_exact');
        }
        $divisor = $quantityScaled;
        $factor = AcceptedProductionQuantity::FACTOR;
        while ($factor !== 0) {
            [$divisor, $factor] = [$factor, $divisor % $factor];
        }
        $greatestCommonDivisor = $divisor;
        $quantityDivisor = intdiv($quantityScaled, $greatestCommonDivisor);
        $rateMultiplier = intdiv(AcceptedProductionQuantity::FACTOR, $greatestCommonDivisor);
        if ($amountMinor % $quantityDivisor !== 0) {
            throw new InvalidArgumentException('approved_acceptance_rate_not_exact');
        }
        $rateBase = intdiv($amountMinor, $quantityDivisor);
        if ($rateBase > intdiv(PHP_INT_MAX, $rateMultiplier)) {
            throw new InvalidArgumentException('approved_acceptance_rate_not_exact');
        }

        [$currency, $currencySource] = $this->currencyWithSource(
            $pivot->getAttribute('currency'),
            $actCurrency,
            'performance_act_completed_works.currency',
        );

        return new ApprovedAcceptanceRate(
            $rateBase * $rateMultiplier,
            $currency,
            'performance_act_completed_works.included_amount_per_quantity@'.$currencySource,
        );
    }

    private function moneyMinor(mixed $value): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', trim((string) $value), $matches) !== 1) {
            throw new InvalidArgumentException('approved_acceptance_money_invalid');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (CurrencyCode::tryFrom($currency) === null) {
            throw new InvalidArgumentException('approved_acceptance_currency_missing');
        }

        return $currency;
    }

    private function currencyWithSource(
        mixed $sourceCurrency,
        ?string $actCurrency,
        string $sourceName,
    ): array {
        if (trim((string) $sourceCurrency) !== '') {
            return [$this->currency($sourceCurrency), $sourceName];
        }

        return [$this->currency($actCurrency), 'contract_performance_act.currency'];
    }
}
