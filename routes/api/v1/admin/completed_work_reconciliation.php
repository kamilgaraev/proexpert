<?php

use App\Http\Controllers\Api\V1\Admin\CompletedWorkReconciliationController;
use Illuminate\Support\Facades\Route;

Route::get('/reconciliation', [CompletedWorkReconciliationController::class, 'index'])
    ->middleware('authorize:completed_works.view,project,project')
    ->name('works.reconciliation');
Route::post('/reconciliation/transform', [CompletedWorkReconciliationController::class, 'transform'])
    ->middleware('authorize:completed_works.edit,project,project')
    ->name('works.reconciliation.transform');
Route::post('/reconciliation/decisions/{completed_work}', [CompletedWorkReconciliationController::class, 'decide'])
    ->middleware('authorize:completed_works.edit,project,project')
    ->whereNumber('completed_work')
    ->name('works.reconciliation.decide');
