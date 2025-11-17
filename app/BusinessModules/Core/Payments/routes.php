<?php

use App\BusinessModules\Core\Payments\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля Payments
| Все маршруты защищены middleware: auth:api_admin, organization.context
|
*/

Route::prefix('api/v1/admin/payments')
    ->name('admin.payments.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
    ->group(function () {
        
        // ============================================
        // Invoices (Счета)
        // ============================================
        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->name('index');
            Route::post('/', [InvoiceController::class, 'store'])->name('store');
            Route::get('/{id}', [InvoiceController::class, 'show'])->name('show');
            Route::put('/{id}', [InvoiceController::class, 'update'])->name('update');
            Route::delete('/{id}', [InvoiceController::class, 'destroy'])->name('destroy');
            
            // Действия над счётом
            Route::post('/{id}/pay', [InvoiceController::class, 'pay'])->name('pay');
            Route::post('/{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        });
    });

