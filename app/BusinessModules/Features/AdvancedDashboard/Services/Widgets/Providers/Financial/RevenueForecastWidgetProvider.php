<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RevenueForecastWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::REVENUE_FORECAST;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $months = $request->getParam('months', 6);

        $historicalFrom = Carbon::now()->subMonths(6);
        $historicalTo = Carbon::now();

        $historicalData = $this->getMonthlyRevenue($request->organizationId, $historicalFrom, $historicalTo);
        $contractBasedForecast = $this->getForecastFromContracts($request->organizationId, $months);
        $trendForecast = $this->calculateTrendForecast($historicalData, $months);
        $combinedForecast = $this->combineForecast($contractBasedForecast, $trendForecast);

        return [
            'months' => $months,
            'forecast_months' => $months,
            'forecast_from' => Carbon::now()->startOfMonth()->toIso8601String(),
            'historical_data' => $historicalData,
            'contract_based_forecast' => $contractBasedForecast,
            'trend_forecast' => $trendForecast,
            'combined_forecast' => $combinedForecast,
            'total_forecasted_revenue' => !empty($combinedForecast) ? array_sum(array_column($combinedForecast, 'amount')) : 0.0,
            'confidence_level' => $this->calculateConfidenceLevel($historicalData),
        ];
    }

    protected function getMonthlyRevenue(int $organizationId, Carbon $from, Carbon $to): array
    {
        $months = [];
        $current = $from->copy()->startOfMonth();

        while ($current->lte($to)) {
            $monthEnd = $current->copy()->endOfMonth();

            $revenue = DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->whereBetween('created_at', [$current, $monthEnd])
                ->where('status', 'active')
                ->sum('total_amount');

            $months[] = [
                'month' => $current->format('Y-m'),
                'amount' => $revenue ? (float)$revenue : 0.0,
            ];

            $current->addMonth();
        }

        return $months;
    }

    protected function getForecastFromContracts(int $organizationId, int $months): array
    {
        $current = Carbon::now()->startOfMonth();
        $forecast = [];

        for ($i = 0; $i < $months; $i++) {
            $monthStart = $current->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $contractsInMonth = Contract::where('organization_id', $organizationId)
                ->where(function($query) use ($monthStart, $monthEnd) {
                    $query->whereBetween('start_date', [$monthStart, $monthEnd])
                        ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                        ->orWhere(function($q) use ($monthStart, $monthEnd) {
                            $q->where('start_date', '<=', $monthStart)
                              ->where('end_date', '>=', $monthEnd);
                        });
                })
                ->whereIn('status', ['active', 'in_progress', 'planned'])
                ->get();

            $totalAmount = 0;

            foreach ($contractsInMonth as $contract) {
                $contractAmount = $contract->total_amount ?? 0;
                $duration = Carbon::parse($contract->start_date)->diffInMonths(Carbon::parse($contract->end_date)) ?: 1;
                $monthlyAmount = $contractAmount / $duration;

                $totalAmount += $monthlyAmount;
            }

            $forecast[] = [
                'month' => $monthStart->format('Y-m'),
                'amount' => round($totalAmount, 2),
                'contracts_count' => $contractsInMonth->count(),
            ];
        }

        return $forecast;
    }

    protected function calculateTrendForecast(array $historicalData, int $months): array
    {
        try {
            if (empty($historicalData) || count($historicalData) < 2) {
                return $this->getDefaultForecast($months);
            }

            $n = count($historicalData);
            $sumX = 0;
            $sumY = 0;
            $sumXY = 0;
            $sumX2 = 0;

            foreach ($historicalData as $index => $data) {
                $x = $index + 1;
                $y = $data['amount'] ?? 0;

                $sumX += $x;
                $sumY += $y;
                $sumXY += $x * $y;
                $sumX2 += $x * $x;
            }

            $denominator = ($n * $sumX2 - $sumX * $sumX);
            if ($denominator == 0) {
                return $this->getDefaultForecast($months);
            }

            $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
            $intercept = ($sumY - $slope * $sumX) / $n;

            $forecast = [];
            $current = Carbon::now()->startOfMonth();

            for ($i = 0; $i < $months; $i++) {
                $x = $n + $i + 1;
                $forecastAmount = $slope * $x + $intercept;
                $forecastAmount = max(0, $forecastAmount);

                $forecast[] = [
                    'month' => $current->copy()->addMonths($i)->format('Y-m'),
                    'amount' => round($forecastAmount, 2),
                ];
            }

            return $forecast;
        } catch (\Exception $e) {
            Log::warning('calculateTrendForecast failed', ['error' => $e->getMessage()]);
            return $this->getDefaultForecast($months);
        }
    }

    protected function getDefaultForecast(int $months): array
    {
        $forecast = [];
        $current = Carbon::now()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $forecast[] = [
                'month' => $current->copy()->addMonths($i)->format('Y-m'),
                'amount' => 0.0,
            ];
        }

        return $forecast;
    }

    protected function combineForecast(array $contractBased, array $trendBased): array
    {
        if (empty($contractBased)) {
            return $trendBased;
        }

        if (empty($trendBased)) {
            return $contractBased;
        }

        $combined = [];

        foreach ($contractBased as $index => $contractData) {
            $trendAmount = $trendBased[$index]['amount'] ?? 0;
            $combinedAmount = ($contractData['amount'] * 0.7) + ($trendAmount * 0.3);

            $combined[] = [
                'month' => $contractData['month'],
                'amount' => round($combinedAmount, 2),
            ];
        }

        return $combined;
    }

    protected function calculateConfidenceLevel(array $historicalData): float
    {
        $dataPoints = count($historicalData);

        if ($dataPoints < 3) {
            return 0.3;
        } elseif ($dataPoints < 6) {
            return 0.6;
        } else {
            return 0.85;
        }
    }
}

