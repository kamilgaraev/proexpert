<?php

declare(strict_types=1);

use App\BusinessModules\Core\Payments\Http\Controllers\Mobile\MobilePaymentDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/mobile/payments/documents')
    ->name('mobile.payments.documents.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->group(function (): void {
        Route::get('/', [MobilePaymentDocumentController::class, 'index'])->name('index');
        Route::post('/', [MobilePaymentDocumentController::class, 'store'])->name('store');
        Route::get('/{id}', [MobilePaymentDocumentController::class, 'show'])->whereNumber('id')->name('show');
        Route::put('/{id}', [MobilePaymentDocumentController::class, 'update'])->whereNumber('id')->name('update');
        Route::post('/{id}/submit', [MobilePaymentDocumentController::class, 'submit'])->whereNumber('id')->name('submit');
        Route::post('/{id}/approve', [MobilePaymentDocumentController::class, 'approve'])->whereNumber('id')->name('approve');
        Route::post('/{id}/reject', [MobilePaymentDocumentController::class, 'reject'])->whereNumber('id')->name('reject');
        Route::post('/{id}/payments', [MobilePaymentDocumentController::class, 'registerPayment'])->whereNumber('id')->name('payments.store');
    });
