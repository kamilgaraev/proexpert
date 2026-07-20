<?php

declare(strict_types=1);

use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationController;
use App\Http\Controllers\Api\V1\Customer\Auth\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\Auth\EmailVerificationController as CustomerEmailVerificationController;
use App\Http\Controllers\Api\V1\Customer\ContractController;
use App\Http\Controllers\Api\V1\Customer\CustomerRequestController;
use App\Http\Controllers\Api\V1\Customer\FinanceController;
use App\Http\Controllers\Api\V1\Customer\InvitationController;
use App\Http\Controllers\Api\V1\Customer\IssueController;
use App\Http\Controllers\Api\V1\Customer\LegalArchiveController;
use App\Http\Controllers\Api\V1\Customer\OrganizationSearchController;
use App\Http\Controllers\Api\V1\Customer\PortalController;
use App\Http\Controllers\Api\V1\Customer\ProjectController;
use App\Http\Controllers\Api\V1\Customer\TeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', [CustomerAuthController::class, 'register'])->name('register');
        Route::post('login', [CustomerAuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->name('reset-password');
        Route::post('email/resend', [CustomerEmailVerificationController::class, 'resend'])
            ->name('verification.resend');
    });
    Route::get('email/verify/{id}/{hash}', [CustomerEmailVerificationController::class, 'verify'])
        ->name('verification.verify');
    Route::middleware(['auth.jwt:api_landing', 'auth.session', 'throttle:dashboard'])
        ->post('refresh', [CustomerAuthController::class, 'refresh'])
        ->name('refresh');

    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'auth.session'])->group(function (): void {
        Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');
        Route::get('email/check', [CustomerEmailVerificationController::class, 'check'])
            ->name('verification.check');
    });
});

Route::get('/invitations/{token}', [InvitationController::class, 'resolve'])->name('invitations.resolve');
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/invitations/{token}/login', [InvitationController::class, 'login'])->name('invitations.login');
    Route::post('/invitations/{token}/register', [InvitationController::class, 'register'])->name('invitations.register');
    Route::post('/invitations/{token}/decline', [InvitationController::class, 'decline'])->name('invitations.decline');
});

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context'])
    ->group(function (): void {
        Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/invitations', [ProjectController::class, 'invitations'])->name('projects.invitations.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/projects/{project}/workspace', [ProjectController::class, 'workspace'])->name('projects.workspace');
        Route::get('/projects/{project}/timeline', [ProjectController::class, 'timeline'])->name('projects.timeline');
        Route::get('/projects/{project}/risks', [ProjectController::class, 'risks'])->name('projects.risks');
        Route::get('/projects/{project}/participants', [ProjectController::class, 'participants'])->name('projects.participants.index');
        Route::post('/projects/{project}/participants/invitations', [ProjectController::class, 'inviteParticipant'])->name('projects.participants.invitations.store');
        Route::post('/projects/{project}/participants/invitations/{invitation}/cancel', [ProjectController::class, 'cancelInvitation'])->name('projects.participants.invitations.cancel');
        Route::post('/projects/{project}/participants/invitations/{invitation}/resend', [ProjectController::class, 'resendInvitation'])->name('projects.participants.invitations.resend');
        Route::get('/projects/{project}/participants/search-organizations', [OrganizationSearchController::class, 'search'])->name('projects.participants.organizations.search');
        Route::get('/projects/{project}/finance', [FinanceController::class, 'projectSummary'])->name('projects.finance');
        Route::get('/projects/{project}/contracts', [ContractController::class, 'projectContracts'])->name('projects.contracts');
        Route::get('/projects/{project}/documents', [ProjectController::class, 'documents'])->name('projects.documents');
        Route::get('/projects/{project}/approvals', [ProjectController::class, 'approvals'])->name('projects.approvals');
        Route::get('/projects/{project}/conversations', [ProjectController::class, 'conversations'])->name('projects.conversations');
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::get('/contracts/{contract}/legal-documents', [LegalArchiveController::class, 'contract'])->name('contracts.legal-documents');
        Route::get('/legal-documents', [LegalArchiveController::class, 'index'])->name('legal-documents.index');
        Route::get('/legal-documents/{document}', [LegalArchiveController::class, 'show'])->whereNumber('document')->name('legal-documents.show');
        Route::get('/legal-document-versions/{version}/preview', [LegalArchiveController::class, 'fileUrl'])
            ->defaults('purpose', 'preview')->whereNumber('version')->name('legal-document-versions.preview');
        Route::get('/legal-document-versions/{version}/download', [LegalArchiveController::class, 'fileUrl'])
            ->defaults('purpose', 'download')->whereNumber('version')->name('legal-document-versions.download');
        Route::post('/legal-workflow-steps/{step}/{action}', [LegalArchiveController::class, 'decide'])
            ->whereNumber('step')->whereIn('action', ['approve', 'reject', 'return'])->name('legal-workflow-steps.decide');
        Route::post('/legal-signature-requests/{signatureRequest}/signing-session', [LegalArchiveController::class, 'signingSession'])
            ->whereNumber('signatureRequest')->name('legal-signature-requests.signing-session');
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
        Route::post('/requests/{requestModel}/resolve', [CustomerRequestController::class, 'resolve'])->name('requests.resolve');
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::get('/team/{member}', [TeamController::class, 'show'])->name('team.show');
        Route::get('/notification-settings', [TeamController::class, 'notificationSettings'])->name('notification-settings.show');
        Route::put('/notification-settings', [TeamController::class, 'updateNotificationSettings'])->name('notification-settings.update');
        Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        Route::get('/analytics/discipline', [PortalController::class, 'disciplineAnalytics'])->name('analytics.discipline');
        Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
        Route::get('/approvals', [PortalController::class, 'approvals'])->name('approvals');
        Route::get('/conversations', [PortalController::class, 'conversations'])->name('conversations');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::get('/notifications/unread-count', [NotificationController::class, 'getUnreadCount'])
            ->name('notifications.unread-count');
        Route::get('/notifications/unread', [NotificationController::class, 'unread'])
            ->name('notifications.unread');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.mark-all-read');
        Route::get('/notifications/{id}', [NotificationController::class, 'show'])
            ->name('notifications.show');
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.mark-as-read');
        Route::patch('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread'])
            ->name('notifications.mark-as-unread');
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])
            ->name('notifications.destroy');
        Route::get('/permissions', [PortalController::class, 'permissions'])->name('permissions');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::get('/support', [PortalController::class, 'supportIndex'])->name('support.index');
        Route::post('/support', [PortalController::class, 'support'])->name('support');
    });
