<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\SupportController;

Route::middleware(['auth:api_landing']) // Исправляем гвард на api_landing
    ->prefix('support')
    ->group(function () {
        Route::post('/', [SupportController::class, 'store'])
             ->name('landing.support.store'); // Имя маршрута
    }); 