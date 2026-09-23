<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileTeamExpansionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('team-expansion')
    ->name('team_expansion.')
    ->group(function (): void {
        Route::get('/contractors', [MobileTeamExpansionController::class, 'contractorIndex'])
            ->middleware('authorize:contractor_marketplace.search.view')
            ->name('contractors.index');
        Route::get('/contractors/{profile}', [MobileTeamExpansionController::class, 'contractorShow'])
            ->whereNumber('profile')
            ->middleware('authorize:contractor_marketplace.profile.view')
            ->name('contractors.show');
        Route::post('/contractor-invitations', [MobileTeamExpansionController::class, 'contractorInvite'])
            ->middleware('authorize:contractor_marketplace.offers.create')
            ->name('contractors.invite');

        Route::get('/brigades', [MobileTeamExpansionController::class, 'brigadeIndex'])
            ->middleware('authorize:brigades.catalog.view')
            ->name('brigades.index');
        Route::get('/brigades/{brigade}', [MobileTeamExpansionController::class, 'brigadeShow'])
            ->whereNumber('brigade')
            ->middleware('authorize:brigades.catalog.view')
            ->name('brigades.show');
        Route::get('/brigade-requests', [MobileTeamExpansionController::class, 'requestIndex'])
            ->middleware('authorize:brigades.requests.view')
            ->name('brigade_requests.index');
        Route::post('/brigade-requests', [MobileTeamExpansionController::class, 'requestStore'])
            ->middleware('authorize:brigades.requests.create')
            ->name('brigade_requests.store');
        Route::get('/brigade-requests/{brigadeRequest}/responses', [MobileTeamExpansionController::class, 'responseIndex'])
            ->whereNumber('brigadeRequest')
            ->middleware('authorize:brigades.responses.view')
            ->name('brigade_requests.responses.index');
        Route::post('/brigade-requests/{brigadeRequest}/responses/{response}/approve', [MobileTeamExpansionController::class, 'responseApprove'])
            ->whereNumber(['brigadeRequest', 'response'])
            ->middleware('authorize:brigades.responses.approve')
            ->name('brigade_requests.responses.approve');
        Route::get('/brigade-invitations', [MobileTeamExpansionController::class, 'invitationIndex'])
            ->middleware('authorize:brigades.invitations.view')
            ->name('brigade_invitations.index');
        Route::post('/brigade-invitations', [MobileTeamExpansionController::class, 'invitationStore'])
            ->middleware('authorize:brigades.invitations.create')
            ->name('brigade_invitations.store');
    });
