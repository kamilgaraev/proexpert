<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Billing\SubscriptionPlanController;
use App\Http\Controllers\Api\Billing\UserSubscriptionController;
use App\Http\Controllers\Api\Billing\BalanceController;

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

        // Маршруты для управления подпиской пользователя
        // GET /api/v1/landing/billing/subscription
        Route::get('subscription', [UserSubscriptionController::class, 'show'])->name('subscription.show');
        // POST /api/v1/landing/billing/subscribe
        Route::post('subscribe', [UserSubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
        // POST /api/v1/landing/billing/subscription/cancel
        Route::post('subscription/cancel', [UserSubscriptionController::class, 'cancel'])->name('subscription.cancel');
        // TODO: Route::post('subscription/switch', [UserSubscriptionController::class, 'switch'])->name('subscription.switch');
        // TODO: Route::post('subscription/resume', [UserSubscriptionController::class, 'resume'])->name('subscription.resume');

        // Управление балансом организации
        // GET /api/v1/landing/billing/balance
        Route::get('balance', [BalanceController::class, 'show'])->name('balance.show');
        // GET /api/v1/landing/billing/balance/transactions
        Route::get('balance/transactions', [BalanceController::class, 'getTransactions'])->name('balance.transactions');
        // POST /api/v1/landing/billing/balance/top-up
        Route::post('balance/top-up', [BalanceController::class, 'topUp'])->name('balance.top-up');
    }); 