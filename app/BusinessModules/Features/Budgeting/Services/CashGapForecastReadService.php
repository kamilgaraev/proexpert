<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapOpeningBalanceSnapshot;
use Carbon\CarbonImmutable;

use function trans_message;

final class CashGapForecastReadService
{
    public function __construct(
        private readonly PaymentCalendarSourceService $sourceService,
        private readonly CashGapForecastService $forecastService,
        private readonly CashGapOpeningBalanceService $openingBalanceService,
    ) {
    }

    public function build(array $request): array
    {
        $currency = $this->nullableCurrency($request['currency'] ?? null);
        $filters = new PaymentCalendarSourceFilters(
            organizationId: (int) $request['organization_id'],
            periodStart: (string) $request['period_start'],
            periodEnd: (string) $request['period_end'],
            projectId: $this->nullableInt($request['project_id'] ?? null),
            counterpartyId: $this->nullableInt($request['counterparty_id'] ?? null),
            budgetArticleId: $request['budget_article_id'] ?? null,
            responsibilityCenterId: $request['responsibility_center_id'] ?? null,
            currency: $currency,
        );
        $calendarItems = $this->sourceService->collect($filters);
        $currencies = $currency !== null ? [$currency] : $this->currencies($calendarItems);
        $balances = $this->openingBalanceService->latestApprovedByCurrency(
            (int) $request['organization_id'],
            (string) $request['period_start'],
            $currencies === [] ? null : $currencies,
        );

        if ($currencies === []) {
            $currencies = array_keys($balances);
        }

        $forecasts = [];
        $unavailable = [];

        foreach ($currencies as $forecastCurrency) {
            $openingBalance = $balances[$forecastCurrency] ?? null;

            if (!$openingBalance instanceof CashGapOpeningBalanceSnapshot) {
                $unavailable[$forecastCurrency] = $this->unavailableCurrency($forecastCurrency);
                continue;
            }

            $forecast = $this->buildCurrencyForecast(
                request: $request,
                currency: $forecastCurrency,
                openingBalance: $openingBalance,
                calendarItems: $calendarItems,
            );
            $forecasts[$forecastCurrency] = $forecast;
        }

        return [
            'available' => $forecasts !== [],
            'partial_unavailable' => $forecasts !== [] && $unavailable !== [],
            'period' => [
                'from' => (string) $request['period_start'],
                'to' => (string) $request['period_end'],
            ],
            'granularity' => (string) $request['granularity'],
            'scenario' => (string) $request['scenario'],
            'filters' => [
                'organization_id' => (int) $request['organization_id'],
                'project_id' => $this->nullableInt($request['project_id'] ?? null),
                'counterparty_id' => $this->nullableInt($request['counterparty_id'] ?? null),
                'budget_article_id' => $request['budget_article_id'] ?? null,
                'responsibility_center_id' => $request['responsibility_center_id'] ?? null,
                'currency' => $currency,
            ],
            'forecasts' => $forecasts,
            'unavailable_currencies' => $unavailable,
            'message' => $forecasts === []
                ? trans_message('budgeting.cash_gap.opening_balance_missing')
                : null,
            'meta' => [
                'currencies' => array_values(array_unique(array_merge(array_keys($forecasts), array_keys($unavailable)))),
                'calendar_items' => count($calendarItems),
                'source_of_truth' => [
                    'opening_balance' => 'management',
                    'payment_calendar' => 'prohelper',
                    'accounting' => '1c',
                ],
            ],
        ];
    }

    private function buildCurrencyForecast(
        array $request,
        string $currency,
        CashGapOpeningBalanceSnapshot $openingBalance,
        array $calendarItems,
    ): array {
        $forecastItems = [];

        foreach ($calendarItems as $item) {
            if (!$item instanceof PaymentCalendarItem || mb_strtoupper($item->currency) !== $currency) {
                continue;
            }

            $forecastItems[] = $item->toCashGapForecastItem();
        }

        $context = $this->context($request, $currency, $openingBalance, $request['scenario_adjustments'] ?? []);
        $forecast = $this->forecastService->forecast($context, $forecastItems)->toArray();
        $forecast['available'] = true;
        $forecast['currency'] = $currency;
        $forecast['granularity'] = (string) $request['granularity'];
        $forecast['series'] = $this->series($forecast['daily'], (string) $request['granularity']);
        $forecast['opening_balance_source'] = $openingBalance->toArray();
        $forecast['comparison'] = $this->comparison($request, $currency, $openingBalance, $forecastItems, $forecast);

        return $forecast;
    }

    private function comparison(
        array $request,
        string $currency,
        CashGapOpeningBalanceSnapshot $openingBalance,
        array $forecastItems,
        array $forecast,
    ): ?array {
        if ((string) $request['scenario'] === CashGapForecastContext::SCENARIO_BASE) {
            return null;
        }

        $base = $this->forecastService->forecast(
            $this->context($request, $currency, $openingBalance, [], CashGapForecastContext::SCENARIO_BASE),
            $forecastItems,
        )->toArray();

        $baseSummary = $this->comparisonSummary($base);
        $scenarioSummary = $this->comparisonSummary($forecast);

        return [
            'base' => $baseSummary,
            'scenario' => $scenarioSummary,
            'delta' => [
                'first_gap_date_changed' => $baseSummary['first_gap_date'] !== $scenarioSummary['first_gap_date'],
                'min_closing_balance' => $this->money($scenarioSummary['min_closing_balance'] - $baseSummary['min_closing_balance']),
                'deficit_amount' => $this->money($scenarioSummary['deficit_amount'] - $baseSummary['deficit_amount']),
                'closing_balance' => $this->money($scenarioSummary['closing_balance'] - $baseSummary['closing_balance']),
            ],
        ];
    }

    private function comparisonSummary(array $forecast): array
    {
        return [
            'first_gap_date' => $forecast['cash_gap']['first_gap_date'] ?? null,
            'min_closing_balance' => (float) ($forecast['cash_gap']['min_closing_balance'] ?? 0.0),
            'deficit_amount' => (float) ($forecast['cash_gap']['deficit_amount'] ?? 0.0),
            'closing_balance' => (float) ($forecast['closing_balance'] ?? 0.0),
        ];
    }

    private function context(
        array $request,
        string $currency,
        CashGapOpeningBalanceSnapshot $openingBalance,
        array $adjustments,
        ?string $scenario = null,
    ): CashGapForecastContext {
        return new CashGapForecastContext(
            periodStart: (string) $request['period_start'],
            periodEnd: (string) $request['period_end'],
            openingBalance: $openingBalance->amount,
            scenario: $scenario ?? (string) $request['scenario'],
            filters: new CashGapForecastFilters(
                organizationId: (int) $request['organization_id'],
                projectId: $this->nullableInt($request['project_id'] ?? null),
                counterpartyId: $this->nullableInt($request['counterparty_id'] ?? null),
                budgetArticleId: $this->nullableString($request['budget_article_id'] ?? null),
                responsibilityCenterId: $this->nullableString($request['responsibility_center_id'] ?? null),
                currency: $currency,
            ),
            scenarioAdjustments: $adjustments,
        );
    }

    private function unavailableCurrency(string $currency): array
    {
        return [
            'currency' => $currency,
            'available' => false,
            'reason' => trans_message('budgeting.cash_gap.opening_balance_missing'),
            'action_hint' => trans_message('budgeting.cash_gap.opening_balance_action_hint'),
        ];
    }

    private function series(array $daily, string $granularity): array
    {
        if ($granularity === 'week') {
            return $this->weeklySeries($daily);
        }

        return $daily;
    }

    private function weeklySeries(array $daily): array
    {
        $weeks = [];

        foreach ($daily as $day) {
            $date = CarbonImmutable::parse((string) $day['date']);
            $weekStart = $date->startOfWeek()->toDateString();
            $weekEnd = $date->endOfWeek()->toDateString();

            $weeks[$weekStart] ??= [
                'date' => $weekStart,
                'period_start' => $weekStart,
                'period_end' => $weekEnd,
                'opening_balance' => (float) $day['opening_balance'],
                'inflows' => 0.0,
                'outflows' => 0.0,
                'reserved_outflows' => 0.0,
                'overdue_inflows' => 0.0,
                'overdue_outflows' => 0.0,
                'closing_balance' => 0.0,
                'cash_gap' => 0.0,
                'risk_level' => CashGapForecastService::RISK_LOW,
                'drivers' => [],
            ];

            $weeks[$weekStart]['inflows'] = $this->money($weeks[$weekStart]['inflows'] + (float) $day['inflows']);
            $weeks[$weekStart]['outflows'] = $this->money($weeks[$weekStart]['outflows'] + (float) $day['outflows']);
            $weeks[$weekStart]['reserved_outflows'] = $this->money($weeks[$weekStart]['reserved_outflows'] + (float) $day['reserved_outflows']);
            $weeks[$weekStart]['overdue_inflows'] = $this->money($weeks[$weekStart]['overdue_inflows'] + (float) $day['overdue_inflows']);
            $weeks[$weekStart]['overdue_outflows'] = $this->money($weeks[$weekStart]['overdue_outflows'] + (float) $day['overdue_outflows']);
            $weeks[$weekStart]['closing_balance'] = (float) $day['closing_balance'];
            $weeks[$weekStart]['cash_gap'] = max($weeks[$weekStart]['cash_gap'], (float) $day['cash_gap']);
            $weeks[$weekStart]['drivers'] = array_merge($weeks[$weekStart]['drivers'], $day['drivers'] ?? []);

            if ($day['risk_level'] === CashGapForecastService::RISK_CRITICAL) {
                $weeks[$weekStart]['risk_level'] = CashGapForecastService::RISK_CRITICAL;
            } elseif (
                $day['risk_level'] === CashGapForecastService::RISK_HIGH
                && $weeks[$weekStart]['risk_level'] !== CashGapForecastService::RISK_CRITICAL
            ) {
                $weeks[$weekStart]['risk_level'] = CashGapForecastService::RISK_HIGH;
            } elseif (
                $day['risk_level'] === CashGapForecastService::RISK_MEDIUM
                && $weeks[$weekStart]['risk_level'] === CashGapForecastService::RISK_LOW
            ) {
                $weeks[$weekStart]['risk_level'] = CashGapForecastService::RISK_MEDIUM;
            }
        }

        return array_values($weeks);
    }

    private function currencies(array $calendarItems): array
    {
        $currencies = [];

        foreach ($calendarItems as $item) {
            if ($item instanceof PaymentCalendarItem) {
                $currencies[] = mb_strtoupper($item->currency);
            }
        }

        return array_values(array_unique($currencies));
    }

    private function nullableCurrency(mixed $value): ?string
    {
        $currency = $this->nullableString($value);

        return $currency === null ? null : mb_strtoupper($currency);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
