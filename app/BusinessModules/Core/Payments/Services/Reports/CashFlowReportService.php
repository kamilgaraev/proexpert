<?php

namespace App\BusinessModules\Core\Payments\Services\Reports;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис отчета Cash Flow (Движение денежных средств)
 */
class CashFlowReportService
{
    /**
     * Получить отчет Cash Flow за период
     */
    public function generate(int $organizationId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $inflows = $this->getInflows($organizationId, $dateFrom, $dateTo);
        $outflows = $this->getOutflows($organizationId, $dateFrom, $dateTo);
        
        // Группируем по дням
        $dailyFlow = $this->calculateDailyFlow($inflows, $outflows, $dateFrom, $dateTo);
        
        // Группируем по неделям
        $weeklyFlow = $this->calculateWeeklyFlow($inflows, $outflows, $dateFrom, $dateTo);
        
        // Группируем по месяцам
        $monthlyFlow = $this->calculateMonthlyFlow($inflows, $outflows, $dateFrom, $dateTo);
        
        // Итоги
        $totalInflow = $inflows->sum('amount');
        $totalOutflow = $outflows->sum('amount');
        $netCashFlow = $totalInflow - $totalOutflow;
        
        // Прогноз на следующий месяц
        $forecast = $this->forecastNextPeriod($organizationId, 30);

        return [
            'period' => [
                'from' => $dateFrom->format('Y-m-d'),
                'to' => $dateTo->format('Y-m-d'),
                'days' => $dateFrom->diffInDays($dateTo) + 1,
            ],
            'summary' => [
                'total_inflow' => round($totalInflow, 2),
                'total_outflow' => round($totalOutflow, 2),
                'net_cash_flow' => round($netCashFlow, 2),
                'average_daily_inflow' => round($totalInflow / max(1, $dateFrom->diffInDays($dateTo) + 1), 2),
                'average_daily_outflow' => round($totalOutflow / max(1, $dateFrom->diffInDays($dateTo) + 1), 2),
            ],
            'daily' => $dailyFlow,
            'weekly' => $weeklyFlow,
            'monthly' => $monthlyFlow,
            'inflows_by_type' => $this->groupByType($inflows),
            'outflows_by_type' => $this->groupByType($outflows),
            'inflows_by_contractor' => $this->groupByContractor($inflows),
            'outflows_by_contractor' => $this->groupByContractor($outflows),
            'forecast_next_month' => $forecast,
        ];
    }

    /**
     * Получить входящие платежи
     */
    private function getInflows(int $organizationId, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        return PaymentTransaction::where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNotNull('payer_contractor_id') // Платежи от контрагентов
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->with(['invoice', 'payerContractor'])
            ->get();
    }

    /**
     * Получить исходящие платежи
     */
    private function getOutflows(int $organizationId, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        return PaymentTransaction::where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNotNull('payee_contractor_id') // Платежи контрагентам
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->with(['invoice', 'payeeContractor'])
            ->get();
    }

    /**
     * Рассчитать ежедневный поток
     */
    private function calculateDailyFlow(Collection $inflows, Collection $outflows, Carbon $dateFrom, Carbon $dateTo): array
    {
        $daily = [];
        $currentDate = $dateFrom->copy();
        $runningBalance = 0;

        while ($currentDate <= $dateTo) {
            $dateStr = $currentDate->format('Y-m-d');
            
            $dayInflows = $inflows->filter(function($t) use ($currentDate) {
                return $t->transaction_date->isSameDay($currentDate);
            });
            
            $dayOutflows = $outflows->filter(function($t) use ($currentDate) {
                return $t->transaction_date->isSameDay($currentDate);
            });
            
            $inflowAmount = $dayInflows->sum('amount');
            $outflowAmount = $dayOutflows->sum('amount');
            $netFlow = $inflowAmount - $outflowAmount;
            $runningBalance += $netFlow;

            $daily[] = [
                'date' => $dateStr,
                'day_of_week' => $currentDate->translatedFormat('l'),
                'inflow' => round($inflowAmount, 2),
                'outflow' => round($outflowAmount, 2),
                'net_flow' => round($netFlow, 2),
                'running_balance' => round($runningBalance, 2),
                'inflow_count' => $dayInflows->count(),
                'outflow_count' => $dayOutflows->count(),
            ];

            $currentDate->addDay();
        }

        return $daily;
    }

    /**
     * Рассчитать еженедельный поток
     */
    private function calculateWeeklyFlow(Collection $inflows, Collection $outflows, Carbon $dateFrom, Carbon $dateTo): array
    {
        $weekly = [];
        $currentWeekStart = $dateFrom->copy()->startOfWeek();

        while ($currentWeekStart <= $dateTo) {
            $currentWeekEnd = $currentWeekStart->copy()->endOfWeek();
            
            $weekInflows = $inflows->filter(function($t) use ($currentWeekStart, $currentWeekEnd) {
                return $t->transaction_date >= $currentWeekStart && $t->transaction_date <= $currentWeekEnd;
            });
            
            $weekOutflows = $outflows->filter(function($t) use ($currentWeekStart, $currentWeekEnd) {
                return $t->transaction_date >= $currentWeekStart && $t->transaction_date <= $currentWeekEnd;
            });
            
            $inflowAmount = $weekInflows->sum('amount');
            $outflowAmount = $weekOutflows->sum('amount');

            $weekly[] = [
                'week_start' => $currentWeekStart->format('Y-m-d'),
                'week_end' => $currentWeekEnd->format('Y-m-d'),
                'week_number' => $currentWeekStart->weekOfYear,
                'inflow' => round($inflowAmount, 2),
                'outflow' => round($outflowAmount, 2),
                'net_flow' => round($inflowAmount - $outflowAmount, 2),
            ];

            $currentWeekStart->addWeek();
        }

        return $weekly;
    }

    /**
     * Рассчитать ежемесячный поток
     */
    private function calculateMonthlyFlow(Collection $inflows, Collection $outflows, Carbon $dateFrom, Carbon $dateTo): array
    {
        $monthly = [];
        $currentMonth = $dateFrom->copy()->startOfMonth();

        while ($currentMonth <= $dateTo) {
            $monthEnd = $currentMonth->copy()->endOfMonth();
            
            $monthInflows = $inflows->filter(function($t) use ($currentMonth, $monthEnd) {
                return $t->transaction_date >= $currentMonth && $t->transaction_date <= $monthEnd;
            });
            
            $monthOutflows = $outflows->filter(function($t) use ($currentMonth, $monthEnd) {
                return $t->transaction_date >= $currentMonth && $t->transaction_date <= $monthEnd;
            });
            
            $inflowAmount = $monthInflows->sum('amount');
            $outflowAmount = $monthOutflows->sum('amount');

            $monthly[] = [
                'month' => $currentMonth->format('Y-m'),
                'month_name' => $currentMonth->translatedFormat('F Y'),
                'inflow' => round($inflowAmount, 2),
                'outflow' => round($outflowAmount, 2),
                'net_flow' => round($inflowAmount - $outflowAmount, 2),
            ];

            $currentMonth->addMonth();
        }

        return $monthly;
    }

    /**
     * Группировка по типам документов
     */
    private function groupByType(Collection $transactions): array
    {
        $grouped = $transactions->groupBy(function($t) {
            return $t->invoice?->document_type?->value ?? 'unknown';
        });

        return $grouped->map(function($items, $type) {
            return [
                'type' => $type,
                'count' => $items->count(),
                'total_amount' => round($items->sum('amount'), 2),
            ];
        })->values()->toArray();
    }

    /**
     * Группировка по контрагентам
     */
    private function groupByContractor(Collection $transactions): array
    {
        $grouped = $transactions->groupBy(function($t) {
            $contractor = $t->payerContractor ?? $t->payeeContractor;
            return $contractor ? $contractor->id : 0;
        });

        return $grouped->map(function($items, $contractorId) {
            $contractor = $items->first()->payerContractor ?? $items->first()->payeeContractor;
            
            return [
                'contractor_id' => $contractorId,
                'contractor_name' => $contractor?->name ?? 'Неизвестно',
                'count' => $items->count(),
                'total_amount' => round($items->sum('amount'), 2),
            ];
        })->sortByDesc('total_amount')->values()->take(10)->toArray();
    }

    /**
     * Прогноз на следующий период
     */
    private function forecastNextPeriod(int $organizationId, int $days = 30): array
    {
        $startDate = Carbon::now();
        $endDate = $startDate->copy()->addDays($days);

        // Запланированные входящие платежи
        $expectedInflows = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'scheduled'])
            ->whereNotNull('payer_contractor_id')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->sum('remaining_amount');

        // Запланированные исходящие платежи
        $expectedOutflows = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'scheduled'])
            ->whereNotNull('payee_contractor_id')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->sum('remaining_amount');

        return [
            'period_days' => $days,
            'expected_inflow' => round($expectedInflows, 2),
            'expected_outflow' => round($expectedOutflows, 2),
            'expected_net_flow' => round($expectedInflows - $expectedOutflows, 2),
        ];
    }

    /**
     * Экспорт в Excel
     */
    public function exportToExcel(array $reportData): string
    {
        // Будет реализовано в ExportService
        return '';
    }
}

