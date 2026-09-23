<?php

declare(strict_types=1);

use App\BusinessModules\Features\Budgeting\Http\Controllers\Mobile\MobileBudgetingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('budgeting/projects/{project}')
    ->whereNumber('project')
    ->name('budgeting.mobile.')
    ->group(function (): void {
        Route::get('/summary', [MobileBudgetingController::class, 'summary'])->name('summary');
        Route::get('/execution-cards', [MobileBudgetingController::class, 'executionCards'])->name('execution_cards');
    });
