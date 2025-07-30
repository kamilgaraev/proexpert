<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractorInvitationController;
use App\Http\Controllers\Api\V1\Admin\OrganizationSearchController;

Route::prefix('contractor-invitations')->group(function () {
    Route::get('/', [ContractorInvitationController::class, 'index'])->name('admin.contractor-invitations.index');
    Route::post('/', [ContractorInvitationController::class, 'store'])->name('admin.contractor-invitations.store');
    Route::get('/stats', [ContractorInvitationController::class, 'stats'])->name('admin.contractor-invitations.stats');
    Route::get('/{id}', [ContractorInvitationController::class, 'show'])->name('admin.contractor-invitations.show');
    Route::patch('/{id}/cancel', [ContractorInvitationController::class, 'cancel'])->name('admin.contractor-invitations.cancel');
});

Route::prefix('organizations')->group(function () {
    Route::get('/search', [OrganizationSearchController::class, 'search'])->name('admin.organizations.search');
    Route::get('/suggestions', [OrganizationSearchController::class, 'suggestions'])->name('admin.organizations.suggestions');
    Route::get('/recommendations', [OrganizationSearchController::class, 'recommendations'])->name('admin.organizations.recommendations');
    Route::get('/{id}/availability', [OrganizationSearchController::class, 'checkAvailability'])->name('admin.organizations.availability');
});