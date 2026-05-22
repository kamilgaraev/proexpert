<?php

declare(strict_types=1);

use App\BusinessModules\Features\WorkflowManagement\Http\Controllers\Mobile\WorkflowTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/mobile/workflow-management')
    ->name('mobile.workflow_management.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'workflow-management.active'])
    ->group(function (): void {
        Route::get('/tasks', [WorkflowTaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [WorkflowTaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks/{task}/approve', [WorkflowTaskController::class, 'approve'])->name('tasks.approve');
        Route::post('/tasks/{task}/reject', [WorkflowTaskController::class, 'reject'])->name('tasks.reject');
        Route::post('/tasks/{task}/request-changes', [WorkflowTaskController::class, 'requestChanges'])->name('tasks.request_changes');
        Route::post('/tasks/{task}/comments', [WorkflowTaskController::class, 'comment'])->name('tasks.comment');
    });
