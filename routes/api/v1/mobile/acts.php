<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileActReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('acts')
    ->name('acts.')
    ->group(function (): void {
        Route::get('/', [MobileActReportController::class, 'index'])->name('index');
        Route::get('/{act}', [MobileActReportController::class, 'show'])->whereNumber('act')->name('show');
        Route::get('/{act}/files/{file}', [MobileActReportController::class, 'downloadFile'])->whereNumber(['act', 'file'])->name('files.download');
        Route::post('/{act}/field-confirmations', [MobileActReportController::class, 'fieldConfirm'])->whereNumber('act')->name('field_confirmations.store');
    });
