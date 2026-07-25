<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
|
| Login защищен строгим rate limiter (защита от брутфорса)
| Остальные auth endpoints используют dashboard rate limiter
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    // Строгий лимит для login (защита от брутфорса)
    Route::middleware(['origin.web:admin', 'throttle:auth'])
        ->post('login', [AuthController::class, 'login'])
        ->name('admin.login');
    
    Route::middleware(['auth.web-refresh:admin', 'origin.web:admin', 'csrf.web:admin', 'throttle:web-refresh'])
        ->post('refresh', [AuthController::class, 'refresh'])
        ->name('refresh');

    Route::middleware(['auth.web-refresh:admin', 'origin.web:admin,true', 'throttle:web-refresh'])
        ->get('csrf', [AuthController::class, 'csrf'])
        ->name('csrf');

    // Защищенные маршруты с более щадящим лимитом
    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'auth.session', 'throttle:dashboard'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware('csrf.web:admin')
            ->name('logout');
    });
}); 
