<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Billing\BankBillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('bank')->name('bank.')->group(function (): void {
    Route::middleware('authorize:billing.view')->group(function (): void {
        Route::get('capabilities', [BankBillingController::class, 'capabilities'])->name('capabilities');
        Route::get('invoices', [BankBillingController::class, 'index'])->name('invoices');
        Route::get('invoices/{invoice}/transfer-qr', [BankBillingController::class, 'transferQr'])->whereUuid('invoice')->name('transfer-qr');
        Route::get('documents/{document}', [BankBillingController::class, 'download'])->whereUuid('document')->name('documents');
        Route::get('payment-proofs/{proof}', [BankBillingController::class, 'downloadProof'])->whereUuid('proof')->name('proof-download');
    });
    Route::middleware('authorize:billing.manage')->group(function (): void {
        Route::post('invoices/{invoice}/payment-proof', [BankBillingController::class, 'paymentProof'])
            ->whereUuid('invoice')->middleware('throttle:6,1')->name('proof-submit');
        Route::post('invoices/{invoice}/qr', [BankBillingController::class, 'qr'])->whereUuid('invoice')->name('qr');
        Route::post('invoices/{invoice}/cancel', [BankBillingController::class, 'cancel'])->whereUuid('invoice')->name('cancel');
        Route::post('invoices/{invoice}/refunds', [BankBillingController::class, 'refund'])->whereUuid('invoice')->name('refunds');
    });
});
