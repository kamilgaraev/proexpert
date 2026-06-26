<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ErpControlsController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp-controls')->name('erp-controls.')->group(function (): void {
    Route::get('policies', [ErpControlsController::class, 'policies'])
        ->middleware('authorize:erp_controls.view')
        ->name('policies.index');

    Route::post('check', [ErpControlsController::class, 'check'])
        ->middleware('authorize:erp_controls.view')
        ->name('check');

    Route::get('conflicts', [ErpControlsController::class, 'conflicts'])
        ->middleware('authorize:erp_controls.sod_conflicts.view')
        ->name('conflicts.index');

    Route::post('conflicts/{conflict}/resolve', [ErpControlsController::class, 'resolveConflict'])
        ->middleware('authorize:erp_controls.sod_conflicts.resolve')
        ->name('conflicts.resolve');

    Route::get('audit', [ErpControlsController::class, 'audit'])
        ->middleware('authorize:erp_controls.audit.view')
        ->name('audit.index');
});
