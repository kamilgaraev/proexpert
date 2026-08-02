<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

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
        $quantityMilli = $this->quantityMilli($pivot->included_quantity);
        $amountMinor = $this->moneyMinor($pivot->included_amount);
        $numerator = $amountMinor * 1000;
        if ($quantityMilli < 1 || $numerator % $quantityMilli !== 0) {
            throw new InvalidArgumentException('approved_acceptance_rate_not_exact');
        }

        [$currency, $currencySource] = $this->currencyWithSource(
            $pivot->getAttribute('currency'),
            $actCurrency,
            'performance_act_completed_works.currency',
        );

        return new ApprovedAcceptanceRate(
            intdiv($numerator, $quantityMilli),
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

    private function quantityMilli(mixed $value): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/D', trim((string) $value), $matches) !== 1) {
            throw new InvalidArgumentException('approved_acceptance_quantity_invalid');
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
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
