<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Billing\SubscriptionPlanController;
use App\Http\Controllers\Api\Billing\UserSubscriptionController;
use App\Http\Controllers\Api\Billing\BalanceController;

// Маршруты биллинга, предполагается, что они будут доступны
// аутентифицированному пользователю (владельцу организации)
// Middleware для аутентификации и проверки роли/прав (например, 'auth:api_landing', 'can:manage_billing')
// должны быть применены в основном файле routes/api.php при подключении этого файла.

// Маршруты для тарифных планов
Route::get('plans', [SubscriptionPlanController::class, 'index'])->name('plans.index');

// Маршруты для управления подпиской пользователя
Route::get('subscription', [UserSubscriptionController::class, 'show'])->name('subscription.show');
Route::post('subscribe', [UserSubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
Route::post('subscription/cancel', [UserSubscriptionController::class, 'cancel'])->name('subscription.cancel');
// TODO: Route::post('subscription/switch', [UserSubscriptionController::class, 'switch'])->name('subscription.switch');
// TODO: Route::post('subscription/resume', [UserSubscriptionController::class, 'resume'])->name('subscription.resume');

// Управление балансом организации
Route::get('balance', [BalanceController::class, 'show'])->name('balance.show');
Route::get('balance/transactions', [BalanceController::class, 'getTransactions'])->name('balance.transactions');
Route::post('balance/top-up', [BalanceController::class, 'topUp'])->name('balance.top-up'); 