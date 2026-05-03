<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Landing\SupportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing'])
    ->prefix('support')
    ->group(function (): void {
        Route::post('/', [SupportController::class, 'store'])
            ->name('landing.support.store');
    });
