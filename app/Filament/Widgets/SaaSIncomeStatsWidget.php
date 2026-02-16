<?php

namespace App\Filament\Widgets;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class SaaSIncomeStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalIncome = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->sum('amount') ?? 0;
            
        $monthIncome = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount') ?? 0;

        return [
            Stat::make(__('widgets.saas_income_stats.total_income'), Number::currency($totalIncome, 'RUB', 'ru'))
                ->description(__('widgets.saas_income_stats.all_time_description'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
                
            Stat::make(__('widgets.saas_income_stats.monthly_income'), Number::currency($monthIncome, 'RUB', 'ru'))
                ->description(__('widgets.saas_income_stats.monthly_description', ['date' => now()->translatedFormat('F Y')]))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),
        ];
    }
}
