<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileSystemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('system')
    ->name('system.')
    ->group(function (): void {
        Route::get('one-c-exchange/status', [MobileSystemController::class, 'oneCStatus'])
            ->middleware('authorize:one_c_exchange.view')
            ->name('one-c-exchange.status');
        Route::get('one-c-exchange/history', [MobileSystemController::class, 'oneCHistory'])
            ->middleware('authorize:one_c_exchange.history.view')
            ->name('one-c-exchange.history');
        Route::post('one-c-exchange/journal/{operationId}/retry', [MobileSystemController::class, 'retryOneCOperation'])
            ->whereNumber('operationId')
            ->middleware('authorize:one_c_exchange.retry')
            ->name('one-c-exchange.journal.retry');

        Route::get('access-recertification/campaigns', [MobileSystemController::class, 'campaigns'])
            ->middleware('authorize:access_recertification.campaigns.view')
            ->name('access-recertification.campaigns.index');
        Route::get('access-recertification/reviews/my', [MobileSystemController::class, 'reviewQueue'])
            ->middleware('authorize:access_recertification.reviews.view')
            ->name('access-recertification.reviews.my');
        Route::post('access-recertification/items/{item}/decisions', [MobileSystemController::class, 'decide'])
            ->whereUuid('item')
            ->middleware('authorize:access_recertification.reviews.decide')
            ->name('access-recertification.items.decide');

        Route::get('rate-coefficients/current', [MobileSystemController::class, 'currentRateCoefficients'])
            ->middleware('authorize:rate_coefficients.view')
            ->name('rate-coefficients.current');

        Route::get('events', [MobileSystemController::class, 'systemEvents'])
            ->middleware('authorize:system-logs.system.view')
            ->name('events.index');
    });
