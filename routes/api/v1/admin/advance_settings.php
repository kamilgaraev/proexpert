<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AdvanceAccountSettingController;

Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'can:access-admin-panel']) // Общая защита для группы
    ->prefix('advance-settings')
    ->name('advance-settings.')
    ->group(function () {
        Route::get('/', [AdvanceAccountSettingController::class, 'index'])->name('index');
        Route::post('/', [AdvanceAccountSettingController::class, 'storeOrUpdate'])->name('storeOrUpdate');
        // Возможно, понадобится право 'can:manage_advance_settings' на уровне роута или контроллера
    }); 