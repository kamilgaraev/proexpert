<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Маршруты API v1
Route::prefix('v1')->name('v1.')->group(function () {
    // Подключаем маршруты для лендинга
    require __DIR__ . '/api/v1/landing/auth.php';
    
    // Подключаем маршруты для админки
    require __DIR__ . '/api/v1/admin/auth.php';
    
    // Подключаем маршруты для мобильного приложения
    require __DIR__ . '/api/v1/mobile/auth.php';
});