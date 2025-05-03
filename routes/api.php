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

// API для лендинга и личного кабинета владельцев организаций (auth_guard = api_landing)
Route::group(['prefix' => 'v1/landing', 'as' => 'api.landing.'], function () {
    // Публичные маршруты (не требуют аутентификации)
    Route::group(['prefix' => 'auth'], function () {
        // Регистрация, вход, восстановление пароля
    });

    // Защищенные маршруты (требуют аутентификации)
    Route::group(['middleware' => ['auth:api_landing']], function () {
        // Организации, подписки, пользователи
    });
});

// API для веб-администрирования (auth_guard = api_admin)
Route::group(['prefix' => 'v1/admin', 'as' => 'api.admin.'], function () {
    // Публичные маршруты (не требуют аутентификации)
    Route::group(['prefix' => 'auth'], function () {
        // Только вход
    });

    // Защищенные маршруты (требуют аутентификации)
    Route::group(['middleware' => ['auth:api_admin']], function () {
        // Управление проектами, материалами, видами работ, сотрудниками, отчетами
    });
});

// API для мобильного приложения (auth_guard = api_mobile)
Route::group(['prefix' => 'v1/mobile', 'as' => 'api.mobile.'], function () {
    // Публичные маршруты (не требуют аутентификации)
    Route::group(['prefix' => 'auth'], function () {
        // Только вход
    });

    // Защищенные маршруты (требуют аутентификации)
    Route::group(['middleware' => ['auth:api_mobile']], function () {
        // Проекты, материалы, виды работ, операции с материалами, синхронизация
    });
}); 