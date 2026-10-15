<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Billing\BankBillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('bank')->name('bank.')->group(function (): void {
    Route::middleware('authorize:billing.view')->group(function (): void {
        Route::get('capabilities', [BankBillingController::class, 'capabilities'])->name('capabilities');
        Route::get('invoices', [BankBillingController::class, 'index'])->name('invoices');
        Route::get('documents/{document}', [BankBillingController::class, 'download'])->whereUuid('document')->name('documents');
    });
    Route::middleware('authorize:billing.manage')->group(function (): void {
        Route::post('invoices/{invoice}/cancel', [BankBillingController::class, 'cancel'])->whereUuid('invoice')->name('cancel');
        Route::post('invoices/{invoice}/refunds', [BankBillingController::class, 'refund'])->whereUuid('invoice')->name('refunds');
    });
});
