<?php

use Illuminate\Support\Facades\Route;
// Контроллер для мобильного API будет создан позже
use App\Http\Controllers\Api\V1\Mobile\Auth\AuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    
}); 