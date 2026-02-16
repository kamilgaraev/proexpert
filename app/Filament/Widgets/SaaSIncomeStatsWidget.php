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
            Stat::make('Total SaaS Income', Number::currency($totalIncome, 'RUB', 'ru'))
                ->description('All time successful payments')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
                
            Stat::make('Monthly Income', Number::currency($monthIncome, 'RUB', 'ru'))
                ->description('Income for ' . now()->format('F Y'))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),
        ];
    }
}
