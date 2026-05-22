<?php

declare(strict_types=1);

use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\Mobile\BudgetEstimateController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/mobile/budget-estimates')
    ->name('mobile.budget_estimates.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'budget-estimates.active'])
    ->group(function (): void {
        Route::get('/summary', [BudgetEstimateController::class, 'summary'])->name('summary');
        Route::get('/estimates', [BudgetEstimateController::class, 'index'])->name('estimates.index');
        Route::get('/estimates/{estimate}', [BudgetEstimateController::class, 'show'])->name('estimates.show');
        Route::post('/estimates/{estimate}/approve', [BudgetEstimateController::class, 'approve'])->name('estimates.approve');
        Route::post('/estimates/{estimate}/request-changes', [BudgetEstimateController::class, 'requestChanges'])->name('estimates.request_changes');
    });
