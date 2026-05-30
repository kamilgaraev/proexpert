<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractorInvitationController;
use App\Http\Controllers\Api\V1\Admin\OrganizationSearchController;

Route::prefix('contractor-invitations')->group(function () {
    Route::get('/', [ContractorInvitationController::class, 'index'])
        ->middleware('authorize:contractor_invitations.view')
        ->name('admin.contractor-invitations.index');
    Route::post('/', [ContractorInvitationController::class, 'store'])
        ->middleware('authorize:contractor_invitations.create')
        ->name('admin.contractor-invitations.store');
    Route::get('/stats', [ContractorInvitationController::class, 'stats'])
        ->middleware('authorize:contractor_invitations.stats')
        ->name('admin.contractor-invitations.stats');
    Route::get('/{id}', [ContractorInvitationController::class, 'show'])
        ->middleware('authorize:contractor_invitations.view')
        ->name('admin.contractor-invitations.show');
    Route::patch('/{id}/cancel', [ContractorInvitationController::class, 'cancel'])
        ->middleware('authorize:contractor_invitations.cancel')
        ->name('admin.contractor-invitations.cancel');
});

Route::prefix('organizations')->group(function () {
    Route::get('/search', [OrganizationSearchController::class, 'search'])
        ->middleware('authorize:organizations.search')
        ->name('admin.organizations.search');
    Route::get('/suggestions', [OrganizationSearchController::class, 'suggestions'])
        ->middleware('authorize:organizations.suggestions')
        ->name('admin.organizations.suggestions');
    Route::get('/recommendations', [OrganizationSearchController::class, 'recommendations'])
        ->middleware('authorize:organizations.recommendations')
        ->name('admin.organizations.recommendations');
    Route::get('/{id}/availability', [OrganizationSearchController::class, 'checkAvailability'])
        ->middleware('authorize:organizations.availability.check')
        ->name('admin.organizations.availability');
});
