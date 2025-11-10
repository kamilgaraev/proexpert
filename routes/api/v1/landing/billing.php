<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Billing\SubscriptionPlanController;
use App\Http\Controllers\Api\Billing\BalanceController;
use App\Http\Controllers\Api\Landing\OrganizationSubscriptionController;
use App\Http\Controllers\Api\V1\Landing\OrganizationDashboardController;
use App\Http\Controllers\Api\V1\Landing\Billing\SubscriptionLimitsController;

// Маршруты биллинга, предполагается, что они будут доступны
// аутентифицированному пользователю (владельцу организации)
// Middleware для аутентификации и проверки роли/прав (например, 'auth:api_landing', 'can:manage_billing')
// должны быть применены в основном файле routes/api.php при подключении этого файла.

Route::middleware(['auth:api_landing', 'authorize:billing.manage', 'interface:lk']) // Новая система авторизации
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
        // POST /api/v1/landing/billing/subscription/cancel
        Route::post('subscription/cancel', [OrganizationSubscriptionController::class, 'cancel'])->name('subscription.cancel');
        // POST /api/v1/landing/billing/subscription/change-plan-preview
        Route::post('subscription/change-plan-preview', [OrganizationSubscriptionController::class, 'changePlanPreview'])->name('subscription.change_plan_preview');
        // POST /api/v1/landing/billing/subscription/change-plan
        Route::post('subscription/change-plan', [OrganizationSubscriptionController::class, 'changePlan'])->name('subscription.change_plan');
        // PATCH /api/v1/landing/billing/subscription/auto-payment
        Route::patch('subscription/auto-payment', [OrganizationSubscriptionController::class, 'updateAutoPayment'])->name('subscription.auto_payment');
        // PATCH /api/v1/landing/billing/subscription
        Route::patch('subscription', [OrganizationSubscriptionController::class, 'update'])->name('subscription.update');

        // Управление балансом организации
        // GET /api/v1/landing/billing/balance
        Route::get('balance', [BalanceController::class, 'show'])->name('balance.show');
        // GET /api/v1/landing/billing/balance/transactions
        Route::get('balance/transactions', [BalanceController::class, 'getTransactions'])->name('balance.transactions');
        // POST /api/v1/landing/billing/balance/top-up - ОТКЛЮЧЕНО (mock платежи вырезаны)
        // Route::post('balance/top-up', [BalanceController::class, 'topUp'])->name('balance.top-up');

        // --- Дашборд ---
        Route::get('dashboard', [OrganizationDashboardController::class, 'index'])->name('dashboard.index');
        
        // --- Лимиты подписки ---
        Route::get('subscription/limits', [SubscriptionLimitsController::class, 'show'])->name('subscription.limits');
    }); 