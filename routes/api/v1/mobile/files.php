<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileFieldFileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('files')
    ->name('files.')
    ->group(function (): void {
        Route::get('/', [MobileFieldFileController::class, 'index'])->name('index');
        Route::post('/', [MobileFieldFileController::class, 'store'])->middleware('throttle:api')->name('store');
        Route::get('/{fileId}', [MobileFieldFileController::class, 'show'])->whereNumber('fileId')->name('show');
    });
