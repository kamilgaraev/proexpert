<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Landing\Billing\BalanceController;
use App\Http\Controllers\Api\V1\Landing\Billing\CommercialCheckoutController;
use App\Http\Controllers\Api\V1\Landing\Billing\CommercialRenewalController;
use App\Http\Controllers\Api\V1\Landing\OrganizationDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['interface:lk'])
    ->name('billing.')
    ->group(function (): void {
        Route::middleware(['authorize:billing.view'])->group(function (): void {
            Route::get('commercial/renewal', [CommercialRenewalController::class, 'show'])->name('commercial.renewal.show');
            Route::get('balance', [BalanceController::class, 'show'])->name('balance.show');
            Route::get('balance/transactions', [BalanceController::class, 'getTransactions'])
                ->name('balance.transactions');
            Route::get('dashboard', [OrganizationDashboardController::class, 'index'])
                ->name('dashboard.index');
        });

        Route::post('commercial/checkout', [CommercialCheckoutController::class, 'store'])
            ->middleware(['authorize:billing.manage'])
            ->name('commercial.checkout');
        Route::post('commercial/renewal/disable', [CommercialRenewalController::class, 'disable'])
            ->middleware(['authorize:billing.manage'])->name('commercial.renewal.disable');
    });
