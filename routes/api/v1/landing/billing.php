<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Billing\SubscriptionPlanController;
use App\Http\Controllers\Api\Billing\BalanceController;
use App\Http\Controllers\Api\Landing\OrganizationSubscriptionAddonController;
use App\Http\Controllers\Api\Landing\OrganizationSubscriptionController;
use App\Http\Controllers\Api\Landing\OrganizationOneTimePurchaseController;
use App\Http\Controllers\Api\V1\Landing\OrganizationDashboardController;
use App\Http\Controllers\Api\V1\Landing\Billing\SubscriptionLimitsController;

// Маршруты биллинга, предполагается, что они будут доступны
// аутентифицированному пользователю (владельцу организации)
// Middleware для аутентификации и проверки роли/прав (например, 'auth:api_landing', 'can:manage_billing')
// должны быть применены в основном файле routes/api.php при подключении этого файла.

Route::middleware(['auth:api_landing', 'role:organization_owner']) // Применяем гард и проверку роли
    ->name('billing.') // Префикс для имен маршрутов
    ->group(function () {
        // Маршруты для тарифных планов
        // GET /api/v1/landing/billing/plans
        Route::get('plans', [SubscriptionPlanController::class, 'index'])->name('plans.index');

        // Маршруты для управления подпиской организации
        // GET /api/v1/landing/billing/subscription
        Route::get('subscription', [OrganizationSubscriptionController::class, 'show'])->name('subscription.show');
        // POST /api/v1/landing/billing/subscribe
        Route::post('subscribe', [OrganizationSubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
        // PATCH /api/v1/landing/billing/subscription/auto-payment
        Route::patch('subscription/auto-payment', [OrganizationSubscriptionController::class, 'updateAutoPayment'])->name('subscription.auto_payment');
        // PATCH /api/v1/landing/billing/subscription
        Route::patch('subscription', [OrganizationSubscriptionController::class, 'update'])->name('subscription.update');

        // Управление балансом организации
        // GET /api/v1/landing/billing/balance
        Route::get('balance', [BalanceController::class, 'show'])->name('balance.show');
        // GET /api/v1/landing/billing/balance/transactions
        Route::get('balance/transactions', [BalanceController::class, 'getTransactions'])->name('balance.transactions');
        // POST /api/v1/landing/billing/balance/top-up
        Route::post('balance/top-up', [BalanceController::class, 'topUp'])->name('balance.top-up');

        // --- Дополнительные возможности подписки ---
        // Получить список add-on'ов
        Route::get('addons', [OrganizationSubscriptionAddonController::class, 'index'])->name('addons.index');
        // Подключить add-on
        Route::post('addon', [OrganizationSubscriptionAddonController::class, 'attach'])->name('addon.attach');
        // Отключить add-on
        Route::delete('addon/{id}', [OrganizationSubscriptionAddonController::class, 'detach'])->name('addon.detach');
        // Совершить одноразовую покупку
        Route::post('one-time-purchase', [OrganizationOneTimePurchaseController::class, 'store'])->name('one_time_purchase.store');
        // Получить историю одноразовых покупок
        Route::get('one-time-purchases', [OrganizationOneTimePurchaseController::class, 'index'])->name('one_time_purchase.index');

        // --- Дашборд ---
        Route::get('dashboard', [OrganizationDashboardController::class, 'index'])->name('dashboard.index');
        
        // --- Лимиты подписки ---
        Route::get('subscription/limits', [SubscriptionLimitsController::class, 'show'])->name('subscription.limits');
    }); 