<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Customer\PortalController;
use App\Http\Controllers\Api\V1\Customer\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->group(function () {
        Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/projects/{project}/documents', [ProjectController::class, 'documents'])->name('projects.documents');
        Route::get('/projects/{project}/approvals', [ProjectController::class, 'approvals'])->name('projects.approvals');
        Route::get('/projects/{project}/conversations', [ProjectController::class, 'conversations'])->name('projects.conversations');
        Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
        Route::get('/approvals', [PortalController::class, 'approvals'])->name('approvals');
        Route::get('/conversations', [PortalController::class, 'conversations'])->name('conversations');
        Route::get('/notifications', [PortalController::class, 'notifications'])->name('notifications');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::post('/support', [PortalController::class, 'support'])->name('support');
    });
