<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class SaaSIncomeStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Считаем только успешные платежи (status = succeeded)
        // В PaymentTransaction статус успешного платежа может быть 'completed'
        // Но в модели Payment (которую мы используем как прокси или если она есть) - 'succeeded'
        
        // Проверяем, есть ли модель Payment, если нет - используем PaymentTransaction
        // В данном проекте похоже используется PaymentTransaction как основной, но есть и Payment.
        // Судя по view_file PaymentTransaction.php, там статус COMPLETED.
        
        // ДАВАЙТЕ ПРОВЕРИМ PaymentTransaction
        
        $totalIncome = \App\BusinessModules\Core\Payments\Models\PaymentTransaction::query()
            ->where('status', 'completed')
            ->sum('amount');
            
        $monthIncome = \App\BusinessModules\Core\Payments\Models\PaymentTransaction::query()
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

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
