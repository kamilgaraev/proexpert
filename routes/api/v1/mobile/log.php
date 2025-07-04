<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\LogController;

/*
|--------------------------------------------------------------------------
| Mobile API Log Routes
|--------------------------------------------------------------------------
|
| Маршруты для логирования действий прораба в мобильном приложении.
|
*/

Route::middleware(['auth:api_mobile', 'can:access-mobile-app'])->group(function () {
    // Логирование материалов
    Route::post('logs/material-receipts', [LogController::class, 'storeMaterialReceipt'])
        ->name('mobile.logs.material-receipts.store');
    Route::post('logs/material-write-offs', [LogController::class, 'storeMaterialWriteOff'])
        ->name('mobile.logs.material-write-offs.store');
}); 