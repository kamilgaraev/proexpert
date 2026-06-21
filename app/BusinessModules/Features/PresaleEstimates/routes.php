<?php

declare(strict_types=1);

use App\BusinessModules\Features\PresaleEstimates\Http\Controllers\PresaleBudgetTransferController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/presale-estimates/budget-transfer')
    ->name('admin.presale_estimates.budget_transfer.')
    ->middleware(AdminRouteStack::middleware())
    ->group(function (): void {
        Route::post('/preview', [PresaleBudgetTransferController::class, 'preview'])
            ->middleware('authorize:presale_estimates.transfer.preview')
            ->name('preview');
        Route::post('/validate', [PresaleBudgetTransferController::class, 'validateTransfer'])
            ->middleware('authorize:presale_estimates.transfer.validate')
            ->name('validate');
        Route::post('/convert', [PresaleBudgetTransferController::class, 'convert'])
            ->middleware('authorize:presale_estimates.transfer.convert')
            ->name('convert');
    });
