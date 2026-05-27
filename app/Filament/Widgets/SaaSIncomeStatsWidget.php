<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;

use function trans_message;

class SaaSIncomeStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::DASHBOARD_REVENUE_VIEW,
                FilamentPermission::BILLING_REVENUE_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        [$totalIncome, $monthIncome] = self::paymentTransactionsTableIsAvailable()
            ? self::incomeTotals()
            : [0, 0];

        return [
            Stat::make(trans_message('widgets.saas_income_stats.total_income'), self::formatCurrency($totalIncome))
                ->description(trans_message('widgets.saas_income_stats.all_time_description'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make(trans_message('widgets.saas_income_stats.monthly_income'), self::formatCurrency($monthIncome))
                ->description(trans_message('widgets.saas_income_stats.monthly_description', ['date' => now()->translatedFormat('F Y')]))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),
        ];
    }

    /**
     * @return array{0: int|float|string|null, 1: int|float|string|null}
     */
    private static function incomeTotals(): array
    {
        $totalIncome = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->sum('amount') ?? 0;

        $monthIncome = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount') ?? 0;

        return [$totalIncome, $monthIncome];
    }

    private static function paymentTransactionsTableIsAvailable(): bool
    {
        $table = (new PaymentTransaction())->getTable();

        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'amount')
            && Schema::hasColumn($table, 'status')
            && Schema::hasColumn($table, 'created_at');
    }

    private static function formatCurrency(int | float | string | null $amount): string
    {
        $normalizedAmount = self::normalizeCurrencyAmount($amount);

        if (extension_loaded('intl')) {
            return Number::currency($normalizedAmount, 'RUB', 'ru');
        }

        return number_format($normalizedAmount, 2, ',', ' ') . ' ₽';
    }

    private static function normalizeCurrencyAmount(int | float | string | null $amount): float
    {
        return (float) ($amount ?? 0);
    }
}
