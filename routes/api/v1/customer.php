<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Customer\ContractController;
use App\Http\Controllers\Api\V1\Customer\CustomerRequestController;
use App\Http\Controllers\Api\V1\Customer\FinanceController;
use App\Http\Controllers\Api\V1\Customer\Auth\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\Auth\EmailVerificationController as CustomerEmailVerificationController;
use App\Http\Controllers\Api\V1\Customer\InvitationController;
use App\Http\Controllers\Api\V1\Customer\IssueController;
use App\Http\Controllers\Api\V1\Customer\PortalController;
use App\Http\Controllers\Api\V1\Customer\ProjectController;
use App\Http\Controllers\Api\V1\Customer\TeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [CustomerAuthController::class, 'register'])->name('register');
    Route::post('login', [CustomerAuthController::class, 'login'])->name('login');
    Route::get('email/verify/{id}/{hash}', [CustomerEmailVerificationController::class, 'verify'])
        ->name('verification.verify');

    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing'])->group(function (): void {
        Route::post('email/resend', [CustomerEmailVerificationController::class, 'resend'])
            ->name('verification.resend');
        Route::get('email/check', [CustomerEmailVerificationController::class, 'check'])
            ->name('verification.check');
    });
});

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->group(function (): void {
        Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/projects/{project}/finance', [FinanceController::class, 'projectSummary'])->name('projects.finance');
        Route::get('/projects/{project}/contracts', [ContractController::class, 'projectContracts'])->name('projects.contracts');
        Route::get('/projects/{project}/documents', [ProjectController::class, 'documents'])->name('projects.documents');
        Route::get('/projects/{project}/approvals', [ProjectController::class, 'approvals'])->name('projects.approvals');
        Route::get('/projects/{project}/conversations', [ProjectController::class, 'conversations'])->name('projects.conversations');
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::get('/finance/summary', [FinanceController::class, 'summary'])->name('finance.summary');
        Route::get('/issues', [IssueController::class, 'index'])->name('issues.index');
        Route::post('/issues', [IssueController::class, 'store'])->name('issues.store');
        Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
        Route::post('/issues/{issue}/comments', [IssueController::class, 'addComment'])->name('issues.comments.store');
        Route::post('/issues/{issue}/resolve', [IssueController::class, 'resolve'])->name('issues.resolve');
        Route::get('/requests', [CustomerRequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [CustomerRequestController::class, 'store'])->name('requests.store');
        Route::get('/requests/{requestModel}', [CustomerRequestController::class, 'show'])->name('requests.show');
        Route::post('/requests/{requestModel}/comments', [CustomerRequestController::class, 'addComment'])->name('requests.comments.store');
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::get('/notification-settings', [TeamController::class, 'notificationSettings'])->name('notification-settings.show');
        Route::put('/notification-settings', [TeamController::class, 'updateNotificationSettings'])->name('notification-settings.update');
        Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
        Route::get('/approvals', [PortalController::class, 'approvals'])->name('approvals');
        Route::get('/conversations', [PortalController::class, 'conversations'])->name('conversations');
        Route::get('/notifications', [PortalController::class, 'notifications'])->name('notifications');
        Route::get('/permissions', [PortalController::class, 'permissions'])->name('permissions');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::get('/support', [PortalController::class, 'supportIndex'])->name('support.index');
        Route::post('/support', [PortalController::class, 'support'])->name('support');
    });
